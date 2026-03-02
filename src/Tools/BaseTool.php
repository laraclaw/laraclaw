<?php

namespace LaraClaw\Tools;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use LaraClaw\Enums\ChannelType;
use LaraClaw\Message;
use LaraClaw\Models\UserAccount;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

use function LaraClaw\Support\interpolate;

/**
 * Base class for tools that dispatch named operations, with built-in confirmation, storage, and channel helpers.
 */
abstract class BaseTool implements Tool
{
    protected array $requiresConfirmation = [];

    public function __construct(protected Message $message) {}

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

        if (! $this->message->channel->confirm($this->message, $prompt)) {
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
     * Return true if $path resolves outside the disk root.
     *
     * For existing paths uses realpath() so symlinks cannot escape the root.
     * For paths not yet on disk, manually normalises the candidate so that
     * write/mkdir operations are also protected.
     */
    protected function pathEscapesDisk(string $disk, string $path): bool
    {
        $root = config("filesystems.disks.{$disk}.root");

        if (! $root) {
            return str_contains($path, '..');
        }

        $root = rtrim($root, '/');
        $candidate = $root . '/' . ltrim($path, '/');

        $real = realpath($candidate);

        if ($real !== false) {
            return ! str_starts_with($real, $root . '/') && $real !== $root;
        }

        return ! str_starts_with($this->normalizePath($candidate), $root . '/');
    }

    /**
     * Resolve the target channel and key for scheduling tools.
     * Falls back to the current message's channel when no override is given.
     *
     * @return array{0: ChannelType, 1: string}
     */
    protected function resolveChannel(?string $channelType): array
    {
        if ($channelType) {
            $userAccount = UserAccount::where('user_id', config('laraclaw.auth.admin_user_id'))
                ->where('channel', $channelType)
                ->first();

            if ($userAccount) {
                return [$userAccount->channel, $userAccount->account];
            }
        }

        return [ChannelType::from($this->message->channel->name), $this->message->conversationKey];
    }

    /**
     * Return true if the path falls within the protected attachments directory.
     */
    protected function isProtectedPath(string $path): bool
    {
        $normalized = trim($path, '/');

        $attachmentsPath = config('laraclaw.filesystem.attachments_path', 'attachments');

        return $normalized === $attachmentsPath || str_starts_with($normalized, $attachmentsPath . '/');
    }

    /**
     * Collapse . and .. segments without touching the filesystem.
     */
    private function normalizePath(string $path): string
    {
        $parts = explode('/', str_replace('\\', '/', $path));
        $result = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
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
