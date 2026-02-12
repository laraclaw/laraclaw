<?php

namespace App\Channels\DTOs;

class Attachment
{
    public function __construct(
        public readonly AttachmentType $type,
        public readonly string $path,
        public readonly ?string $disk = null,
        public readonly ?string $mimeType = null,
        public readonly ?int $size = null,
        public readonly ?string $filename = null,
    ) {}
}
