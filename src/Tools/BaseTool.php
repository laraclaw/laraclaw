<?php

namespace Laraclaw\Tools;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Models\Account;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

use function Laraclaw\Support\interpolate;

/**
 * Base class for tools that dispatch named operations, with built-in approval gating and storage helpers.
 */
abstract class BaseTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    /**
     * Operations that pause the agent for human approval, mapped to the prompt
     * shown to the user. Each value is either a template string interpolated
     * with the request arguments, or a closure returning the prompt.
     */
    protected array $requiresApproval = [];

    /**
     * Bind the inbound message so tool operations can resolve the active connector and key.
     */
    public function __construct(protected IncomingMessage $message) {}

    /**
     * Validate the requested operation, then dispatch to the method.
     *
     * Approval is handled by the SDK before this ever runs, so a gated call
     * only reaches this point once the user has approved it.
     */
    public function handle(Request $request): Stringable|string
    {
        $operation = $request['operation'];

        if (! in_array($operation, $this->operations(), true)) {
            return "Unknown operation '{$operation}'. Available: " . implode(', ', $this->operations());
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
     * Pause the agent for approval when the requested operation is gated.
     *
     * The prompt built here becomes the approval reason, which is what the
     * connector shows the user while the run is paused.
     *
     * The SDK resolves this outside the tool's own error handling, both when the
     * call is first gated and again when the run resumes, so it reads arguments
     * defensively. A model that leaves out an optional argument should not be
     * able to take down the whole run from here.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        $template = $this->requiresApproval[$request->string('operation')->value()] ?? null;

        if ($template === null) {
            return false;
        }

        return Approval::required(is_callable($template)
            ? $template($request)
            : interpolate($template, $request->toArray()));
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
     * Validate that a file may be read from the given disk and path.
     *
     * This is the full set of filesystem rules: the disk allowlist, the traversal
     * check, and the attachment directories the agent is not allowed to touch.
     * Tools that hand file contents to the outside world should go through here so
     * they cannot drift away from what FileManager enforces.
     *
     * Returns an error string, or null if access is permitted.
     */
    protected function validateFileAccess(string $disk, string $path): ?string
    {
        if ($error = $this->validateDiskAccess($disk, $path)) {
            return $error;
        }

        if ($this->isProtectedPath($path)) {
            return "Cannot read system directory '{$path}'.";
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
