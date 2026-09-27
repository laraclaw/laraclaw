<?php

namespace Laraclaw\Tools;

use Exception;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Exceptions\OutboundRequestBlocked;
use Laraclaw\Services\Attachments;
use Laraclaw\Services\OutboundRequestPolicy;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

/**
 * Agent tool for managing files on Laravel storage disks (list, read, write, move, etc.).
 */
class FileManager extends BaseTool
{
    private const int MAX_READ_BYTES = 100 * 1024;

    private const int DOWNLOAD_TIMEOUT = 30;

    protected array $requires = [
        'write' => ['content'],
        'append' => ['content'],
        'move' => ['destination'],
        'copy' => ['destination'],
        'save_attachment' => ['source'],
        'download_url' => ['url'],
    ];

    /**
     * Bind the inbound message, the attachment writer and the outbound request
     * policy, then register the delete approval prompt.
     */
    public function __construct(
        protected IncomingMessage $message,
        private readonly Attachments $attachments,
        private readonly OutboundRequestPolicy $policy = new OutboundRequestPolicy,
    ) {
        $this->requiresApproval['delete'] = function (Request $request): string {
            $paths = $this->requestedPaths($request)->map(fn (string $path): string => "`{$path}`")->implode(', ');

            return "Delete {$paths} from disk \"{$request->string('disk')}\"?";
        };
    }

    /**
     * Return the tool description shown to the agent.
     */
    public function description(): Stringable|string
    {
        $disks = implode(', ', config('laraclaw.filesystem.allowed_disks', []));

        return 'Read, write, list, move, copy and delete files on a Laravel storage disk. '
            . 'Use this for any plain filesystem work; do not reach for Tinker to write or read a file. '
            . "Allowed disks: {$disks}. Operations: " . implode(', ', $this->operations()) . '.';
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
        // Both keys are optional in the schema. A batch delete passes only "paths",
        // so reading "path" directly here threw before any operation could run.
        // Each operation validates the paths it actually uses.
        if ($error = $this->validateDiskAccess($request['disk'] ?? '', $request['path'] ?? '')) {
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

        return collect($storage->files($path))
            ->map(fn (string $file): array => ['name' => $file, 'size' => $storage->size($file), 'type' => 'file'])
            ->merge(collect($storage->directories($path))
                ->map(fn (string $dir): array => ['name' => $dir, 'size' => 0, 'type' => 'directory']))
            ->reject(fn (array $entry): bool => $this->isProtectedPath($entry['name']))
            ->toJson(JSON_PRETTY_PRINT);
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

        $contents = (string) $storage->get($path);

        if (! mb_check_encoding($contents, 'UTF-8')) {
            return "Cannot read {$path}: binary file.";
        }

        return strlen($contents) > self::MAX_READ_BYTES
            ? substr($contents, 0, self::MAX_READ_BYTES) . "\n\n[Truncated: file exceeds 100KB]"
            : $contents;
    }

    /**
     * Write content to a file, overwriting if it already exists.
     */
    protected function write(Request $request): string
    {
        $this->storage($request)->put($request['path'], $request['content']);

        return "Written to {$request['path']}.";
    }

    /**
     * Append content to an existing file.
     */
    protected function append(Request $request): string
    {
        $this->storage($request)->append($request['path'], $request['content']);

        return "Appended to {$request['path']}.";
    }

    /**
     * Delete one or more files after user confirmation.
     */
    protected function delete(Request $request): string
    {
        $storage = $this->storage($request);
        $paths = $this->requestedPaths($request);

        if ($paths->isEmpty()) {
            return 'No paths provided for delete.';
        }

        foreach ($paths as $path) {
            if ($this->pathEscapesDisk($request['disk'], $path)) {
                return 'Path traversal is not allowed.';
            }

            if ($this->isProtectedPath($path)) {
                return "Cannot delete system directory '{$path}'.";
            }
        }

        return $paths
            ->map(fn (string $path): string => $path . ': ' . $this->deletePath($storage, $path))
            ->implode('; ') . '.';
    }

    /**
     * Move a file to a destination path, renaming it if something already exists there.
     */
    protected function move(Request $request): string
    {
        if ($this->isProtectedPath($request['path'])) {
            return "Cannot move system directory '{$request['path']}'.";
        }

        return $this->transfer($request, 'moved', fn (Filesystem $storage, string $from, string $to) => $storage->move($from, $to));
    }

    /**
     * Copy a file to a destination path, renaming it if something already exists there.
     */
    protected function copy(Request $request): string
    {
        return $this->transfer($request, 'copied', fn (Filesystem $storage, string $from, string $to) => $storage->copy($from, $to));
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
        $source = $request['source'];
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
     *
     * The transfer goes through the same outbound policy the web_request tool
     * uses, so a private address is refused here too, whether it is the target
     * or the destination of a redirect. The body is streamed to a temporary file
     * and handed to the disk as a stream, so a large file never sits in memory.
     */
    protected function downloadUrl(Request $request): string
    {
        $url = $request['url'];

        try {
            $temporary = $this->policy->download($url, self::DOWNLOAD_TIMEOUT, $this->maxDownloadBytes());
        } catch (OutboundRequestBlocked $e) {
            return $e->getMessage();
        } catch (Exception $e) {
            return "Failed to download URL: {$e->getMessage()}";
        }

        $stream = null;

        try {
            $path = $request['path'];

            // A path with no extension names a directory, so the file takes its name from the URL.
            if (! pathinfo((string) $path, PATHINFO_EXTENSION)) {
                $path = rtrim((string) $path, '/') . '/' . $this->filenameFromUrl((string) $url);
            }

            // The guard in handle() only saw the path the model asked for, so the
            // derived one has to be checked again before anything is written.
            if ($error = $this->validateDiskAccess($request['disk'] ?? '', $path)) {
                return $error;
            }

            $storage = $this->storage($request);
            $actual = $this->uniqueFilePath($storage, $path);
            $stream = fopen($temporary, 'r');

            if ($stream === false) {
                return "Could not open the downloaded file for {$url}.";
            }

            return $storage->put($actual, $stream)
                ? "Downloaded to {$actual}."
                : "Could not write the download to {$actual}.";
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * Move or copy the file to its destination, picking a free name when the destination is taken.
     */
    private function transfer(Request $request, string $verb, callable $action): string
    {
        $storage = $this->storage($request);
        $path = $request['path'];

        if (! $storage->exists($path)) {
            return "File not found: {$path}";
        }

        $actual = $this->uniqueFilePath($storage, $request['destination']);
        $action($storage, $path, $actual);

        return $actual !== $request['destination']
            ? "'{$request['destination']}' was taken, {$verb} {$path} to '{$actual}'."
            : ucfirst($verb) . " {$path} to {$actual}.";
    }

    /**
     * Delete whatever lives at the path and report what happened to it.
     */
    private function deletePath(Filesystem $storage, string $path): string
    {
        if ($storage->fileExists($path)) {
            $storage->delete($path);

            return $storage->fileExists($path) ? 'failed to delete file' : 'deleted';
        }

        if ($storage->directoryExists($path)) {
            $storage->deleteDirectory($path);

            return $storage->directoryExists($path) ? 'failed to delete directory' : 'deleted';
        }

        return 'not found';
    }

    /**
     * Read the paths a delete names, whether the model sent the plural or the singular argument.
     *
     * array() and ?? tolerate a missing key. Plain $request['paths'] does not: the
     * model usually sends only the singular "path", and the elvis operator reads
     * the key before testing it, so it throws instead of falling back.
     */
    private function requestedPaths(Request $request): Collection
    {
        return collect($request->array('paths') ?: [$request['path'] ?? null])->filter()->values();
    }

    /**
     * Return the given path if it is free, otherwise append an incrementing integer
     * until a unique path is found.
     */
    private function uniqueFilePath(Filesystem $storage, string $path): string
    {
        $dir = dirname($path) === '.' ? '' : dirname($path) . '/';
        $name = pathinfo($path, PATHINFO_FILENAME);
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return $this->firstFree(
            fn (int $i): string => $i === 0 ? $path : $dir . $name . $i . ($ext !== '' ? '.' . $ext : ''),
            fn (string $candidate): bool => $storage->exists($candidate),
        );
    }

    /**
     * Return the given directory path if it is free, otherwise append an incrementing integer.
     */
    private function uniqueDirPath(Filesystem $storage, string $path): string
    {
        $normalized = rtrim($path, '/');

        return $this->firstFree(
            fn (int $i): string => $i === 0 ? $path : $normalized . $i,
            fn (string $candidate): bool => $storage->directoryExists(rtrim($candidate, '/')),
        );
    }

    /**
     * Walk the candidates from zero up and return the first one that is not taken.
     */
    private function firstFree(callable $candidate, callable $taken): string
    {
        for ($i = 0; ; $i++) {
            if (! $taken($candidate($i))) {
                return $candidate($i);
            }
        }
    }

    /**
     * Work out a filename for a download whose target path names a directory.
     *
     * basename() hands back "." or ".." for a URL ending in a dot segment, which
     * would aim the write at the directory itself or at its parent, so those are
     * treated the same as an empty result and replaced with a generated name.
     */
    private function filenameFromUrl(string $url): string
    {
        $candidate = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_BASENAME);

        return in_array($candidate, ['', '.', '..'], true) ? (string) Str::uuid() : $candidate;
    }

    /**
     * Return the largest download the agent is allowed to write to a disk.
     */
    private function maxDownloadBytes(): int
    {
        return (int) config('laraclaw.http.max_download_bytes', 25 * 1024 * 1024);
    }
}
