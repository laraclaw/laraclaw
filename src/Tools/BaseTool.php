<?php

namespace Laraclaw\Tools;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Laraclaw\Connectors\Connector;
use Laraclaw\Connectors\Contracts\SupportsConfirmation;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Models\Account;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

use function Laraclaw\Support\interpolate;

/**
 * Base class for tools that dispatch named operations, with built-in confirmation, storage, and connector helpers.
 */
abstract class BaseTool implements Tool
{
    protected array $requiresConfirmation = [];

    protected ?Connector $connector = null;

    /**
     * Bind the inbound message so tool operations can resolve the active connector and key.
     */
    public function __construct(protected IncomingMessage $message) {}

    /**
     * Set the active connector so confirmation prompts can reach the user.
     */
    public function withConnector(Connector $connector): static
    {
        $this->connector = $connector;

        return $this;
    }

    /**
     * Validate the requested operation, run optional confirmation, then dispatch to the method.
     */
    public function handle(Request $request): Stringable|string
    {
        $operation = $request['operation'];

        if (! in_array($operation, $this->operations(), true)) {
            return "Unknown operation '{$operation}'. Available: " . implode(', ', $this->operations());
        }

        if ($denied = $this->confirmOperation($request, $operation)) {
            return $denied;
        }

        // Operation names use snake_case (e.g. "save_attachment") because that is what the JSON schema
        // exposes to the model, but PHP methods are camelCase. Convert here so subclasses can just
        // define saveAttachment() without any extra routing boilerplate.
        $method = str_contains($operation, '_') ? lcfirst(str_replace('_', '', ucwords($operation, '_'))) : $operation;

        return $this->{$method}($request);
    }

    /**
     * Return the list of supported operation names for this tool.
     *
     * @return string[]
     */
    abstract protected function operations(): array;

    /**
     * If the operation requires confirmation, prompt the user and return 'Cancelled by user.'
     * on denial, or null to proceed.
     */
    protected function confirmOperation(Request $request, string $operation): ?string
    {
        if (! isset($this->requiresConfirmation[$operation])) {
            return null;
        }

        $template = $this->requiresConfirmation[$operation];

        $prompt = is_callable($template)
            ? $template($request)
            : interpolate($template, $request->toArray());

        if (! $this->connector instanceof SupportsConfirmation || ! $this->connector->askForConfirmation($this->message, $prompt)) {
            return 'Cancelled by user.';
        }

        return null;
    }

    /**
     * Return the filesystem disk specified in the request.
     */
    protected function storage(Request $request): Filesystem
    {
        return Storage::disk($request['disk']);
    }

    /**
     * Validate that the requested disk is allowed and the path stays within the disk root.
     * Returns an error string, or null if access is permitted.
     */
    protected function validateDiskAccess(string $disk, string $path): ?string
    {
        $allowed = config('laraclaw.filesystem.allowed_disks', []);

        if (! in_array($disk, $allowed, true)) {
            return "Disk '{$disk}' is not allowed. Allowed disks: " . implode(', ', $allowed);
        }

        if ($this->pathEscapesDisk($disk, $path)) {
            return 'Path traversal is not allowed.';
        }

        return null;
    }

    /**
     * Return true if the path resolves outside the disk root.
     *
     * For existing paths, realpath() is used so symlinks cannot escape the root.
     * For paths that do not exist yet, the candidate is normalized by hand so
     * new writes are also covered.
     */
    protected function pathEscapesDisk(string $disk, string $path): bool
    {
        $root = config("filesystems.disks.{$disk}.root");

        if (! $root) {
            return str_contains($path, '..');
        }

        $root = rtrim((string) $root, '/');
        $candidate = $root . '/' . ltrim($path, '/');

        $real = realpath($candidate);

        if ($real !== false) {
            return ! str_starts_with($real, $root . '/') && $real !== $root;
        }

        $normalized = $this->normalizePath($candidate);

        return ! str_starts_with($normalized, $root . '/') && $normalized !== $root;
    }

    /**
     * Resolve the target connector and key for scheduling tools.
     * Falls back to the current message's connector when no override is given.
     *
     * @return array{0: ConnectorType, 1: string}
     */
    protected function resolveConnector(?string $connectorType): array
    {
        if ($connectorType) {
            $account = Account::where('user_id', config('laraclaw.auth.admin_user_id'))
                ->where('connector', $connectorType)
                ->first();

            if ($account) {
                return [$account->connector, $account->account];
            }
        }

        return [$this->message->connector, $this->message->key];
    }

    /**
     * Return true if the path falls within the protected attachments directory.
     */
    protected function isProtectedPath(string $path): bool
    {
        $normalized = trim($path, '/');

        $protected = [
            config('laraclaw.filesystem.incoming_attachments_path', 'inbound'),
            config('laraclaw.filesystem.outgoing_attachments_path', 'outbound'),
        ];

        return array_any($protected, fn ($root): bool => $normalized === $root || str_starts_with($normalized, $root . '/'));
    }

    /**
     * Collapse . and .. segments without touching the filesystem.
     */
    private function normalizePath(string $path): string
    {
        $parts = explode('/', str_replace('\\', '/', $path));
        $result = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if ($part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($result);
            } else {
                $result[] = $part;
            }
        }

        return '/' . implode('/', $result);
    }
}
