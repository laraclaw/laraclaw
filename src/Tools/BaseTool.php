<?php

namespace LaraClaw\Tools;

use LaraClaw\Channels\Channel;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

abstract class BaseTool implements Tool
{
    protected array $requiresConfirmation = [];

    public function __construct(protected Channel $channel) {}

    abstract protected function operations(): array;

    public function handle(Request $request): Stringable|string
    {
        $operation = $request['operation'];

        if (! in_array($operation, $this->operations(), true)) {
            return "Unknown operation '{$operation}'. Available: ".implode(', ', $this->operations());
        }

        if ($denied = $this->confirmOperation($operation, $request)) {
            return $denied;
        }

        $method = str_contains($operation, '_') ? lcfirst(str_replace('_', '', ucwords($operation, '_'))) : $operation;

        return $this->{$method}($request);
    }

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
        $allowed = config('laraclaw.tools.allowed_disks', []);

        if (! in_array($disk, $allowed, true)) {
            return "Disk '{$disk}' is not allowed. Allowed disks: ".implode(', ', $allowed);
        }

        if (str_contains($path, '..')) {
            return 'Path traversal is not allowed.';
        }

        return null;
    }

    protected function isProtectedPath(string $path): bool
    {
        $normalized = trim($path, '/');

        return collect(config('laraclaw.tools.system_directories', []))
            ->contains(fn ($dir) => $normalized === $dir || str_starts_with($normalized, $dir.'/'));
    }
}
