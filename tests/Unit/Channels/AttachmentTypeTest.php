<?php

use App\Channels\DTOs\AttachmentType;

it('resolves image types from mime type', function (string $mimeType) {
    expect(AttachmentType::fromMimeType($mimeType))->toBe(AttachmentType::Image);
})->with([
    'image/png',
    'image/jpeg',
    'image/gif',
    'image/webp',
]);

it('resolves audio types from mime type', function (string $mimeType) {
    expect(AttachmentType::fromMimeType($mimeType))->toBe(AttachmentType::Audio);
})->with([
    'audio/mpeg',
    'audio/ogg',
    'audio/wav',
]);

it('resolves video types from mime type', function (string $mimeType) {
    expect(AttachmentType::fromMimeType($mimeType))->toBe(AttachmentType::Video);
})->with([
    'video/mp4',
    'video/webm',
    'video/quicktime',
]);

it('resolves unknown types as document', function (string $mimeType) {
    expect(AttachmentType::fromMimeType($mimeType))->toBe(AttachmentType::Document);
})->with([
    'application/pdf',
    'application/zip',
    'text/plain',
    'text/csv',
]);
