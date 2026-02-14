<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class DiskManager implements Tool
{
    private const MAX_READ_BYTES = 100 * 1024;

    private const OPERATIONS = ['list', 'read', 'write', 'append', 'delete', 'move', 'copy', 'exists', 'mkdir', 'save_attachment'];

    private const SYSTEM_DIRECTORIES = ['attachments', 'personas'];

    public function description(): Stringable|string
    {
        $disks = implode(', ', config('laraclaw.disk_manager.allowed_disks', []));
        $ops = implode(', ', self::OPERATIONS);

        return "Manage files on disk. Allowed disks: {$disks}. Operations: {$ops}.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->required()->description('The operation to perform: ' . implode(', ', self::OPERATIONS)),
            'disk' => $schema->string()->required()->description('The storage disk to use'),
            'path' => $schema->string()->required()->description('The file or directory path. For save_attachment, this is the source attachment path on its original disk.'),
            'destination' => $schema->string()->description('Destination path for move/copy/save_attachment operations'),
            'content' => $schema->string()->description('Content for write/append operations'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $operation = $request['operation'];
        $disk = $request['disk'];
        $path = $request['path'];
        $destination = $request['destination'] ?? null;
        $content = $request['content'] ?? null;

        $allowedDisks = config('laraclaw.disk_manager.allowed_disks', []);

        if (! in_array($disk, $allowedDisks, true)) {
            return "Disk '{$disk}' is not allowed. Allowed disks: " . implode(', ', $allowedDisks);
        }

        if (str_contains($path, '..') || ($destination !== null && str_contains($destination, '..'))) {
            return 'Path traversal is not allowed.';
        }

        if (! in_array($operation, self::OPERATIONS, true)) {
            return "Unknown operation '{$operation}'. Available: " . implode(', ', self::OPERATIONS);
        }

        if (in_array($operation, ['move', 'delete'], true) && $this->isProtectedPath($path)) {
            return "Cannot {$operation} system directory '{$path}'.";
        }

        Log::info('DiskManager: executing', compact('operation', 'disk', 'path'));

        $storage = Storage::disk($disk);

        return match ($operation) {
            'list' => $this->list($storage, $path),
            'read' => $this->read($storage, $path),
            'write' => $this->write($storage, $path, $content),
            'append' => $this->append($storage, $path, $content),
            'delete' => $this->delete($storage, $path),
            'move' => $this->move($storage, $path, $destination),
            'copy' => $this->copy($storage, $path, $destination),
            'exists' => $this->exists($storage, $path),
            'mkdir' => $this->mkdir($storage, $path),
            'save_attachment' => $this->saveAttachment($storage, $path, $destination),
        };
    }

    private function list(Filesystem $storage, string $path): string
    {
        $entries = [];

        foreach ($storage->files($path) as $file) {
            $entries[] = ['name' => $file, 'size' => $storage->size($file), 'type' => 'file'];
        }

        foreach ($storage->directories($path) as $dir) {
            $entries[] = ['name' => $dir, 'size' => 0, 'type' => 'directory'];
        }

        return json_encode($entries, JSON_PRETTY_PRINT);
    }

    private function read(Filesystem $storage, string $path): string
    {
        if (! $storage->exists($path)) {
            return "File not found: {$path}";
        }

        $contents = $storage->get($path);

        if (strlen($contents) > self::MAX_READ_BYTES) {
            return substr($contents, 0, self::MAX_READ_BYTES) . "\n\n[Truncated — file exceeds 100KB]";
        }

        return $contents;
    }

    private function write(Filesystem $storage, string $path, ?string $content): string
    {
        if ($content === null) {
            return 'The "content" parameter is required for the write operation.';
        }

        $actual = $this->uniqueFilePath($storage, $path);
        $storage->put($actual, $content);

        return $actual !== $path
            ? "'{$path}' was taken, created '{$actual}'."
            : "Written to {$actual}.";
    }

    private function append(Filesystem $storage, string $path, ?string $content): string
    {
        if ($content === null) {
            return 'The "content" parameter is required for the append operation.';
        }

        $storage->append($path, $content);

        return "Appended to {$path}.";
    }

    private function delete(Filesystem $storage, string $path): string
    {
        if (! $storage->exists($path)) {
            return "File not found: {$path}";
        }

        $storage->delete($path);

        return "Deleted {$path}.";
    }

    private function move(Filesystem $storage, string $path, ?string $destination): string
    {
        if ($destination === null) {
            return 'The "destination" parameter is required for the move operation.';
        }

        if (! $storage->exists($path)) {
            return "File not found: {$path}";
        }

        $actual = $this->uniqueFilePath($storage, $destination);
        $storage->move($path, $actual);

        return $actual !== $destination
            ? "'{$destination}' was taken, moved {$path} to '{$actual}'."
            : "Moved {$path} to {$actual}.";
    }

    private function copy(Filesystem $storage, string $path, ?string $destination): string
    {
        if ($destination === null) {
            return 'The "destination" parameter is required for the copy operation.';
        }

        if (! $storage->exists($path)) {
            return "File not found: {$path}";
        }

        $actual = $this->uniqueFilePath($storage, $destination);
        $storage->copy($path, $actual);

        return $actual !== $destination
            ? "'{$destination}' was taken, copied {$path} to '{$actual}'."
            : "Copied {$path} to {$actual}.";
    }

    private function exists(Filesystem $storage, string $path): string
    {
        return $storage->exists($path)
            ? "File exists: {$path}"
            : "File does not exist: {$path}";
    }

    private function mkdir(Filesystem $storage, string $path): string
    {
        $actual = $this->uniqueDirPath($storage, $path);
        $storage->makeDirectory($actual);

        return $actual !== $path
            ? "'{$path}' was taken, created '{$actual}'."
            : "Directory created: {$actual}.";
    }

    private function uniqueFilePath(Filesystem $storage, string $path): string
    {
        if (! $storage->exists($path)) {
            return $path;
        }

        $dir = dirname($path) === '.' ? '' : dirname($path) . '/';
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $name = pathinfo($path, PATHINFO_FILENAME);

        $i = 1;
        do {
            $candidate = $dir . $name . $i . ($ext !== '' ? '.' . $ext : '');
            $i++;
        } while ($storage->exists($candidate));

        return $candidate;
    }

    private function uniqueDirPath(Filesystem $storage, string $path): string
    {
        $normalized = rtrim($path, '/');

        if (! $storage->directoryExists($normalized)) {
            return $path;
        }

        $i = 1;
        do {
            $candidate = $normalized . $i;
            $i++;
        } while ($storage->directoryExists($candidate));

        return $candidate;
    }

    private function saveAttachment(Filesystem $storage, string $path, ?string $destination): string
    {
        if ($destination === null) {
            return 'The "destination" parameter is required for the save_attachment operation.';
        }

        $sourceDisk = Storage::disk(config('laraclaw.attachments.disk', 'local'));

        if (! $sourceDisk->exists($path)) {
            return "Attachment not found: {$path}";
        }

        $actual = $this->uniqueFilePath($storage, $destination);
        $storage->put($actual, $sourceDisk->get($path));

        return $actual !== $destination
            ? "'{$destination}' was taken, saved attachment to '{$actual}'."
            : "Saved attachment to {$actual}.";
    }

    private function isProtectedPath(string $path): bool
    {
        $normalized = trim($path, '/');

        foreach (self::SYSTEM_DIRECTORIES as $dir) {
            if ($normalized === $dir || str_starts_with($normalized, $dir . '/')) {
                return true;
            }
        }

        return false;
    }
}
