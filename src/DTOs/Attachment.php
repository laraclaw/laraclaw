<?php

namespace Laraclaw\DTOs;

use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;

/**
 * DTO representing a file attachment stored on a Laravel filesystem disk.
 */
class Attachment
{
    /**
     * Build an attachment record pointing at a file on a Laravel disk.
     */
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

    /**
     * Convert this attachment to the AI SDK file type the agent expects.
     */
    public function toAiFile(): Image|Document
    {
        return $this->isImage()
            ? Image::fromStorage($this->path, $this->disk)
            : Document::fromStorage($this->path, $this->disk);
    }
}
