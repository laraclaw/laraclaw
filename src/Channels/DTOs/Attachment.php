<?php

namespace LaraClaw\Channels\DTOs;

class Attachment
{
    public function __construct(
        public readonly AttachmentType $type,
        public readonly string $path,
        public readonly string $disk,
        public readonly ?string $mimeType = null,
        public readonly ?string $filename = null,
    ) {}
}
