<?php

namespace Laraclaw\Tools;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Services\Attachments;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

/**
 * Agent tool for managing files on Laravel storage disks (list, read, write, move, etc.).
 */
class FileManager extends BaseTool
{
    private const MAX_READ_BYTES = 100 * 1024;

    protected array $requiresConfirmation = [];

    public function __construct(protected IncomingMessage $message, private readonly Attachments $attachments)
    {
        $this->requiresConfirmation['delete'] = function (Request $request): string {
            $paths = collect($request['paths'] ?: [$request['path']])->filter();

            return "Delete {$paths->map(fn ($p): string => "`{$p}`")->implode(', ')} from disk \"{$request['disk']}\"?";
        };
    }

    /**
     * Return the tool description shown to the agent.
     */
    public function description(): Stringable|string
    {
        $disks = implode(', ', config('laraclaw.filesystem.allowed_disks', []));

        return "Manage files on disk. Allowed disks: {$disks}. Operations: " . implode(', ', $this->operations()) . '.';
    }

    /**
     * Define the input schema for this tool.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->required()->description('The operation to perform: ' . implode(', ', $this->operations())),
            'disk' => $schema->string()->required()->description('The storage disk to use'),
            'path' => $schema->string()->required()->description('The file or directory path'),
            'paths' => $schema->array()->items($schema->string())->description('Multiple file or directory paths for batch delete'),
            'destination' => $schema->string()->description('Destination path for move/copy operations'),
            'content' => $schema->string()->description('Content for write/append operations'),
            'source' => $schema->string()->description('Source path on the attachments disk (for save_attachment)'),
            'url' => $schema->string()->description('URL to download (for download_url)'),
        ];
    }

    /**
     * Validate disk and path access, then delegate to the requested operation.
     */
    #[Override]
    public function handle(Request $request): Stringable|string
    {
        if ($error = $this->validateDiskAccess($request['disk'], $request['path'])) {
            return $error;
        }

        $destination = $request['destination'] ?? null;

        if ($destination !== null && $this->pathEscapesDisk($request['disk'], $destination)) {
            return 'Path traversal is not allowed.';
        }

        return parent::handle($request);
    }

    /**
     * Return the list of supported operation names.
     */
    protected function operations(): array
    {
        return ['list', 'read', 'write', 'append', 'delete', 'move', 'copy', 'exists', 'mkdir', 'save_attachment', 'attach_to_reply', 'download_url'];
    }

    // Operations

    /**
     * List files and directories at the given path.
     */
    protected function list(Request $request): string
    {
        $storage = $this->storage($request);
        $path = $request['path'];

        if ($this->isProtectedPath($path)) {
            return "Cannot list system directory '{$path}'.";
        }

        $entries = collect($storage->files($path))
            ->map(fn ($file): array => ['name' => $file, 'size' => $storage->size($file), 'type' => 'file'])
            ->merge(collect($storage->directories($path))
                ->map(fn ($dir): array => ['name' => $dir, 'size' => 0, 'type' => 'directory']))
            ->reject(fn ($entry): bool => $this->isProtectedPath($entry['name']));

        return $entries->toJson(JSON_PRETTY_PRINT);
    }

    /**
     * Read and return the contents of a text file, truncated to 100KB.
     */
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

        if (strlen((string) $contents) > self::MAX_READ_BYTES) {
            return substr((string) $contents, 0, self::MAX_READ_BYTES) . "\n\n[Truncated: file exceeds 100KB]";
        }

        return $contents;
    }

    /**
     * Write content to a file, overwriting if it already exists.
     */
    protected function write(Request $request): string
    {
        if (($request['content'] ?? null) === null) {
            return 'The "content" parameter is required for the write operation.';
        }

        $storage = $this->storage($request);
        $storage->put($request['path'], $request['content']);

        return "Written to {$request['path']}.";
    }

    /**
     * Append content to an existing file.
     */
    protected function append(Request $request): string
    {
        if (($request['content'] ?? null) === null) {
            return 'The "content" parameter is required for the append operation.';
        }

        $this->storage($request)->append($request['path'], $request['content']);

        return "Appended to {$request['path']}.";
    }

    /**
     * Delete one or more files after user confirmation.
     */
    protected function delete(Request $request): string
    {
        $storage = $this->storage($request);
        $paths = collect($request['paths'] ?: [$request['path']])->filter()->values()->all();

        if (empty($paths)) {
            return 'No paths provided for delete.';
        }

        foreach ($paths as $p) {
            if ($this->pathEscapesDisk($request['disk'], $p)) {
                return 'Path traversal is not allowed.';
            }
            if ($this->isProtectedPath($p)) {
                return "Cannot delete system directory '{$p}'.";
            }
        }

        return collect($paths)
            ->map(function ($p) use ($storage): string {
                if ($storage->fileExists($p)) {
                    $storage->delete($p);

                    return $storage->fileExists($p)
                        ? "{$p}: failed to delete file"
                        : "{$p}: deleted";
                }

                if ($storage->directoryExists($p)) {
                    $storage->deleteDirectory($p);

                    return $storage->directoryExists($p)
                        ? "{$p}: failed to delete directory"
                        : "{$p}: deleted";
                }

                return "{$p}: not found";
            })
            ->implode('; ') . '.';
    }

    /**
     * Move a file to a destination path, renaming it if something already exists there.
     */
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

    /**
     * Copy a file to a destination path, renaming it if something already exists there.
     */
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

    /**
     * Check whether a file exists at the given path.
     */
    protected function exists(Request $request): string
    {
        $path = $request['path'];

        return $this->storage($request)->exists($path)
            ? "File exists: {$path}"
            : "File does not exist: {$path}";
    }

    /**
     * Create a directory, renaming it if the name is already taken.
     */
    protected function mkdir(Request $request): string
    {
        $storage = $this->storage($request);
        $actual = $this->uniqueDirPath($storage, $request['path']);
        $storage->makeDirectory($actual);

        return $actual !== $request['path']
            ? "'{$request['path']}' was taken, created '{$actual}'."
            : "Directory created: {$actual}.";
    }

    /**
     * Queue a stored file to be attached to the agent's reply.
     */
    protected function attachToReply(Request $request): string
    {
        $storage = $this->storage($request);
        $path = $request['path'];

        if (! $storage->exists($path)) {
            return "File not found: {$path}";
        }

        $this->attachments->outbound($this->message->uuid)->set(basename((string) $path), $storage->get($path));

        return "'{$path}' will be attached to your reply.";
    }

    /**
     * Copy an inbound attachment from the attachments disk to a target disk and path.
     */
    protected function saveAttachment(Request $request): string
    {
        $source = $request['source'] ?? null;

        if ($source === null) {
            return 'The "source" parameter is required for the save_attachment operation.';
        }

        $attachmentsDisk = Storage::disk(config('laraclaw.filesystem.attachments_disk', 'local'));

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

    /**
     * Download a remote URL and save it to the specified disk path.
     */
    protected function downloadUrl(Request $request): string
    {
        $url = $request['url'] ?? null;

        if (! $url) {
            return 'The "url" parameter is required for the download_url operation.';
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return "Invalid URL: {$url}";
        }

        $response = Http::timeout(30)->get($url);

        if (! $response->successful()) {
            return "Failed to download URL (HTTP {$response->status()}): {$url}";
        }

        $storage = $this->storage($request);
        $path = $request['path'];

        // If path looks like a directory (no extension), derive a filename from the URL
        if (! pathinfo((string) $path, PATHINFO_EXTENSION)) {
            $urlFilename = pathinfo(parse_url((string) $url, PHP_URL_PATH), PATHINFO_BASENAME) ?: Str::uuid();
            $path = rtrim((string) $path, '/') . '/' . $urlFilename;
        }

        $actual = $this->uniqueFilePath($storage, $path);
        $storage->put($actual, $response->body());

        return "Downloaded to {$actual}.";
    }

    // Helpers

    /**
     * Return the given path if it is free, otherwise append an incrementing integer
     * until a unique path is found.
     */
    protected function uniqueFilePath(Filesystem $storage, string $path): string
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

    /**
     * Return the given directory path if it is free, otherwise append an incrementing integer.
     */
    protected function uniqueDirPath(Filesystem $storage, string $path): string
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
}
