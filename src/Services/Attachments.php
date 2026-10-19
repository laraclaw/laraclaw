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

    /**
     * Scope subsequent reads and writes to the inbound folder for the given message UUID.
     */
    public function inbound(string $uuid): static
    {
        $this->uuid = $uuid;
        $this->base = config('laraclaw.filesystem.incoming_attachments_path', 'inbound');

        return $this;
    }

    /**
     * Scope subsequent reads and writes to the outbound folder for the given message UUID.
     */
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
        Storage::disk($this->disk())->put($this->path($filename), $content);

        return $this->path($filename);
    }

    /**
     * Stream an uploaded file to storage and return its full path.
     */
    public function putFile(string $filename, UploadedFile $file): string
    {
        Storage::disk($this->disk())->putFileAs($this->path(), $file, $filename);

        return $this->path($filename);
    }

    /**
     * Read a file from the current scope.
     */
    public function get(string $filename): ?string
    {
        return Storage::disk($this->disk())->get($this->path($filename));
    }

    /**
     * Return all files in the current scope as Attachment DTOs.
     */
    public function getAll(): Collection
    {
        $disk = $this->disk();

        return collect(Storage::disk($disk)->files($this->path()))
            ->map(fn (string $file): Attachment => new Attachment(
                path: $file,
                disk: $disk,
                mimeType: Storage::disk($disk)->mimeType($file) ?: 'application/octet-stream',
                filename: basename($file),
            ));
    }

    /**
     * Build the path of the current scope's folder, or of a file inside it.
     */
    private function path(?string $filename = null): string
    {
        return collect([$this->base, $this->uuid, $filename])->reject(fn (?string $segment): bool => $segment === null || $segment === '')->implode('/');
    }

    /**
     * Return the configured Laravel disk name used for both inbound and outbound storage.
     */
    private function disk(): string
    {
        return config('laraclaw.filesystem.attachments_disk', 'local');
    }
}
