<?php

namespace LaraClaw\Tools;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;
use Stringable;

class Files extends BaseTool
{
    private const MAX_READ_BYTES = 100 * 1024;

    protected array $requiresConfirmation = [
        'move' => 'Move "{path}" to "{destination}"?',
    ];

    protected function operations(): array
    {
        return ['list', 'read', 'write', 'append', 'delete', 'move', 'copy', 'exists', 'mkdir', 'save_attachment'];
    }

    public function description(): Stringable|string
    {
        $disks = implode(', ', config('laraclaw.tools.allowed_disks', []));

        return "Manage files on disk. Allowed disks: {$disks}. Operations: ".implode(', ', $this->operations()).'.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->required()->description('The operation to perform: '.implode(', ', $this->operations())),
            'disk' => $schema->string()->required()->description('The storage disk to use'),
            'path' => $schema->string()->required()->description('The file or directory path'),
            'paths' => $schema->array()->items($schema->string())->description('Multiple file paths for batch delete'),
            'destination' => $schema->string()->description('Destination path for move/copy operations'),
            'content' => $schema->string()->description('Content for write/append operations'),
            'source' => $schema->string()->description('Source path on the attachments disk (for save_attachment)'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        if ($error = $this->validateDiskAccess($request['disk'], $request['path'])) {
            return $error;
        }

        $destination = $request['destination'] ?? null;

        if ($destination !== null && str_contains($destination, '..')) {
            return 'Path traversal is not allowed.';
        }

        return parent::handle($request);
    }

    // Operations ----------------------------------------

    protected function list(Request $request): string
    {
        $storage = $this->storage($request);
        $path = $request['path'];

        $entries = collect($storage->files($path))
            ->map(fn ($file) => ['name' => $file, 'size' => $storage->size($file), 'type' => 'file'])
            ->merge(collect($storage->directories($path))
                ->map(fn ($dir) => ['name' => $dir, 'size' => 0, 'type' => 'directory']));

        return $entries->toJson(JSON_PRETTY_PRINT);
    }

    protected function read(Request $request): string
    {
        $storage = $this->storage($request);
        $path = $request['path'];

        if (! $storage->exists($path)) {
            return "File not found: {$path}";
        }

        $contents = $storage->get($path);

        if (! mb_check_encoding($contents, 'UTF-8')) {
            return "Cannot read {$path}: binary file.";
        }

        if (strlen($contents) > self::MAX_READ_BYTES) {
            return substr($contents, 0, self::MAX_READ_BYTES)."\n\n[Truncated — file exceeds 100KB]";
        }

        return $contents;
    }

    protected function write(Request $request): string
    {
        if (($request['content'] ?? null) === null) {
            return 'The "content" parameter is required for the write operation.';
        }

        $storage = $this->storage($request);
        $actual = $this->uniqueFilePath($storage, $request['path']);
        $storage->put($actual, $request['content']);

        return $actual !== $request['path']
            ? "'{$request['path']}' was taken, created '{$actual}'."
            : "Written to {$actual}.";
    }

    protected function append(Request $request): string
    {
        if (($request['content'] ?? null) === null) {
            return 'The "content" parameter is required for the append operation.';
        }

        $this->storage($request)->append($request['path'], $request['content']);

        return "Appended to {$request['path']}.";
    }

    protected function delete(Request $request): string
    {
        $storage = $this->storage($request);
        $paths = ! empty($request['paths']) ? array_values($request['paths']) : [$request['path']];

        foreach ($paths as $p) {
            if (str_contains($p, '..')) {
                return 'Path traversal is not allowed.';
            }
            if ($this->isProtectedPath($p)) {
                return "Cannot delete system directory '{$p}'.";
            }
        }

        $count = count($paths);
        $message = $count === 1
            ? "Delete \"{$paths[0]}\" from disk \"{$request['disk']}\"?"
            : "Delete {$count} files from disk \"{$request['disk']}\": ".implode(', ', $paths).'?';

        if (! $this->channel->confirm($message)) {
            return 'Cancelled by user.';
        }

        return collect($paths)
            ->map(function ($p) use ($storage) {
                if (! $storage->exists($p)) {
                    return "{$p}: not found";
                }

                $storage->delete($p);

                return "{$p}: deleted";
            })
            ->implode('; ').'.';
    }

    protected function move(Request $request): string
    {
        if (($request['destination'] ?? null) === null) {
            return 'The "destination" parameter is required for the move operation.';
        }

        $storage = $this->storage($request);
        $path = $request['path'];

        if ($this->isProtectedPath($path)) {
            return "Cannot move system directory '{$path}'.";
        }

        if (! $storage->exists($path)) {
            return "File not found: {$path}";
        }

        $actual = $this->uniqueFilePath($storage, $request['destination']);
        $storage->move($path, $actual);

        return $actual !== $request['destination']
            ? "'{$request['destination']}' was taken, moved {$path} to '{$actual}'."
            : "Moved {$path} to {$actual}.";
    }

    protected function copy(Request $request): string
    {
        if (($request['destination'] ?? null) === null) {
            return 'The "destination" parameter is required for the copy operation.';
        }

        $storage = $this->storage($request);
        $path = $request['path'];

        if (! $storage->exists($path)) {
            return "File not found: {$path}";
        }

        $actual = $this->uniqueFilePath($storage, $request['destination']);
        $storage->copy($path, $actual);

        return $actual !== $request['destination']
            ? "'{$request['destination']}' was taken, copied {$path} to '{$actual}'."
            : "Copied {$path} to {$actual}.";
    }

    protected function exists(Request $request): string
    {
        $path = $request['path'];

        return $this->storage($request)->exists($path)
            ? "File exists: {$path}"
            : "File does not exist: {$path}";
    }

    protected function mkdir(Request $request): string
    {
        $storage = $this->storage($request);
        $actual = $this->uniqueDirPath($storage, $request['path']);
        $storage->makeDirectory($actual);

        return $actual !== $request['path']
            ? "'{$request['path']}' was taken, created '{$actual}'."
            : "Directory created: {$actual}.";
    }

    protected function saveAttachment(Request $request): string
    {
        $source = $request['source'] ?? null;

        if ($source === null) {
            return 'The "source" parameter is required for the save_attachment operation.';
        }

        $attachmentsDisk = Storage::disk(config('laraclaw.attachments.disk', 'local'));

        if (! $attachmentsDisk->exists($source)) {
            return "Attachment not found: {$source}";
        }

        $storage = $this->storage($request);
        $actual = $this->uniqueFilePath($storage, $request['path']);
        $storage->put($actual, $attachmentsDisk->get($source));

        return $actual !== $request['path']
            ? "'{$request['path']}' was taken, saved attachment to '{$actual}'."
            : "Saved attachment to {$actual}.";
    }

    // Helpers ----------------------------------------

    protected function uniqueFilePath(Filesystem $storage, string $path): string
    {
        if (! $storage->exists($path)) {
            return $path;
        }

        $dir = dirname($path) === '.' ? '' : dirname($path).'/';
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $name = pathinfo($path, PATHINFO_FILENAME);

        $i = 1;

        do {
            $candidate = $dir.$name.$i.($ext !== '' ? '.'.$ext : '');
            $i++;
        } while ($storage->exists($candidate));

        return $candidate;
    }

    protected function uniqueDirPath(Filesystem $storage, string $path): string
    {
        $normalized = rtrim($path, '/');

        if (! $storage->directoryExists($normalized)) {
            return $path;
        }

        $i = 1;

        do {
            $candidate = $normalized.$i;
            $i++;
        } while ($storage->directoryExists($candidate));

        return $candidate;
    }
}
