<?php

namespace LaraClaw\DTOs;

/**
 * DTO representing a file attachment stored on a Laravel filesystem disk.
 */
class Attachment
{
    public function __construct(
        public readonly string $path,
        public readonly string $disk,
        public readonly ?string $mimeType = null,
        public readonly ?string $filename = null,
    ) {}

    /**
     * Determine whether this attachment is an audio file.
     */
    public function isAudio(): bool
    {
        return str_starts_with($this->mimeType ?? '', 'audio/');
    }

    /**
     * Determine whether this attachment is an image file.
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mimeType ?? '', 'image/');
    }

    /**
     * Determine whether this attachment is a document (neither audio nor image).
     */
    public function isDocument(): bool
    {
        return ! $this->isAudio() && ! $this->isImage();
    }
}
