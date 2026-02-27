<?php

namespace LaraClaw\Tools;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use LaraClaw\Channels\Channel;
use LaraClaw\Enums\ChannelType;
use LaraClaw\Models\UserAccount;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

abstract class BaseTool implements Tool
{
    protected array $requiresConfirmation = [];

    public function __construct(protected Channel $channel) {}

    public function handle(Request $request): Stringable|string
    {
        $operation = $request['operation'];

        if (! in_array($operation, $this->operations(), true)) {
            return "Unknown operation '{$operation}'. Available: " . implode(', ', $this->operations());
        }

        if ($denied = $this->confirmOperation($operation, $request)) {
            return $denied;
        }

        $method = str_contains($operation, '_') ? lcfirst(str_replace('_', '', ucwords($operation, '_'))) : $operation;

        return $this->{$method}($request);
    }

    abstract protected function operations(): array;

    protected function confirmOperation(string $operation, Request $request): ?string
    {
        if (! isset($this->requiresConfirmation[$operation])) {
            return null;
        }

        $message = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($request) {
            $value = $request[$m[1]] ?? $m[0];

            return is_array($value) ? implode(', ', $value) : $value;
        }, $this->requiresConfirmation[$operation]);

        if (! $this->channel->confirm($message)) {
            return 'Cancelled by user.';
        }

        return null;
    }

    protected function storage(Request $request): Filesystem
    {
        return Storage::disk($request['disk']);
    }

    protected function validateDiskAccess(string $disk, string $path): ?string
    {
        $allowed = config('laraclaw.filesystem.allowed_disks', []);

        if (! in_array($disk, $allowed, true)) {
            return "Disk '{$disk}' is not allowed. Allowed disks: " . implode(', ', $allowed);
        }

        if (str_contains($path, '..')) {
            return 'Path traversal is not allowed.';
        }

        return null;
    }

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

        [$type, $key] = explode(':', $this->channel->identifier(), 2);

        return [ChannelType::from($type), $key];
    }

    protected function isProtectedPath(string $path): bool
    {
        $normalized = trim($path, '/');

        $attachmentsPath = config('laraclaw.filesystem.attachments_path', 'attachments');

        return $normalized === $attachmentsPath || str_starts_with($normalized, $attachmentsPath . '/');
    }
}
