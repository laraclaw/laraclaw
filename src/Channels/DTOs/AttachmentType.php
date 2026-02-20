<?php

namespace LaraClaw\Channels\DTOs;

enum AttachmentType: string
{
    case Image = 'image';
    case Audio = 'audio';
    case Video = 'video';
    case Document = 'document';

    public static function fromMimeType(string $mimeType): self
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => self::Image,
            str_starts_with($mimeType, 'audio/') => self::Audio,
            str_starts_with($mimeType, 'video/') => self::Video,
            default => self::Document,
        };
    }
}
