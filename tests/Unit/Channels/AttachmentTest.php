<?php

use App\Channels\DTOs\Attachment;
use App\Channels\DTOs\AttachmentType;

it('stores all properties', function () {
    $attachment = new Attachment(
        type: AttachmentType::Image,
        path: 'laraclaw/attachments/test.png',
        disk: 'local',
        mimeType: 'image/png',
        size: 1024,
        filename: 'test.png',
    );

    expect($attachment->type)->toBe(AttachmentType::Image)
        ->and($attachment->path)->toBe('laraclaw/attachments/test.png')
        ->and($attachment->disk)->toBe('local')
        ->and($attachment->mimeType)->toBe('image/png')
        ->and($attachment->size)->toBe(1024)
        ->and($attachment->filename)->toBe('test.png');
});

it('has sensible defaults for optional properties', function () {
    $attachment = new Attachment(
        type: AttachmentType::Document,
        path: 'laraclaw/attachments/file.pdf',
    );

    expect($attachment->disk)->toBeNull()
        ->and($attachment->mimeType)->toBeNull()
        ->and($attachment->size)->toBeNull()
        ->and($attachment->filename)->toBeNull();
});

it('is serializable', function () {
    $attachment = new Attachment(
        type: AttachmentType::Audio,
        path: 'laraclaw/attachments/voice.ogg',
        disk: 'local',
        mimeType: 'audio/ogg',
        size: 2048,
        filename: 'voice.ogg',
    );

    $unserialized = unserialize(serialize($attachment));

    expect($unserialized)->toEqual($attachment);
});
