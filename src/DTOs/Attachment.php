<?php

namespace LaraClaw\DTOs;

class Attachment
{
    public function __construct(
        public readonly string $path,
        public readonly string $disk,
        public readonly ?string $mimeType = null,
        public readonly ?string $filename = null,
    ) {}

    public function isAudio(): bool
    {
        return str_starts_with($this->mimeType ?? '', 'audio/');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType ?? '', 'image/');
    }

    public function isDocument(): bool
    {
        return ! $this->isAudio() && ! $this->isImage();
    }
}
