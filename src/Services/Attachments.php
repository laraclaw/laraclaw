<?php

namespace Laraclaw\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Laraclaw\DTOs\Attachment;

/**
 * Reads and writes inbound and outbound attachment files, scoped to a message UUID.
 */
class Attachments
{
    private string $uuid;
    private string $base;

    public function inbound(string $uuid): static
    {
        $this->uuid = $uuid;
        $this->base = config('laraclaw.filesystem.incoming_attachments_path', 'inbound');

        return $this;
    }

    public function outbound(string $uuid): static
    {
        $this->uuid = $uuid;
        $this->base = config('laraclaw.filesystem.outgoing_attachments_path', 'outbound');

        return $this;
    }

    /**
     * Write a file and return its full storage path.
     */
    public function set(string $filename, string $content): string
    {
        $path = "{$this->base}/{$this->uuid}/{$filename}";
        Storage::disk($this->disk())->put($path, $content);

        return $path;
    }

    /**
     * Stream an uploaded file to storage and return its full path.
     */
    public function putFile(string $filename, UploadedFile $file): string
    {
        $directory = "{$this->base}/{$this->uuid}";
        Storage::disk($this->disk())->putFileAs($directory, $file, $filename);

        return "{$directory}/{$filename}";
    }

    /**
     * Read a file from the current scope.
     */
    public function get(string $filename): ?string
    {
        return Storage::disk($this->disk())->get("{$this->base}/{$this->uuid}/{$filename}");
    }

    /**
     * Return all files in the current scope as Attachment DTOs.
     */
    public function getAll(): Collection
    {
        $disk = $this->disk();

        return collect(Storage::disk($disk)->files("{$this->base}/{$this->uuid}"))
            ->map(fn (string $file): Attachment => new Attachment(
                path: $file,
                disk: $disk,
                mimeType: Storage::disk($disk)->mimeType($file) ?: 'application/octet-stream',
                filename: basename($file),
            ));
    }

    private function disk(): string
    {
        return config('laraclaw.filesystem.attachments_disk', 'local');
    }
}
