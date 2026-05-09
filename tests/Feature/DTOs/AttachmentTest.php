<?php

use Laraclaw\DTOs\Attachment;

it('detects audio files', function () {
    $a = new Attachment(path: 'test.mp3', disk: 'local', mimeType: 'audio/mpeg');

    expect($a->isAudio())->toBeTrue()
        ->and($a->isImage())->toBeFalse()
        ->and($a->isDocument())->toBeFalse();
});

it('detects image files', function () {
    $a = new Attachment(path: 'photo.jpg', disk: 'local', mimeType: 'image/jpeg');

    expect($a->isImage())->toBeTrue()
        ->and($a->isAudio())->toBeFalse()
        ->and($a->isDocument())->toBeFalse();
});

it('treats non-audio non-image as document', function () {
    $a = new Attachment(path: 'file.pdf', disk: 'local', mimeType: 'application/pdf');

    expect($a->isDocument())->toBeTrue()
        ->and($a->isAudio())->toBeFalse()
        ->and($a->isImage())->toBeFalse();
});

it('treats null mime type as document', function () {
    $a = new Attachment(path: 'file.bin', disk: 'local');

    expect($a->isAudio())->toBeFalse()
        ->and($a->isImage())->toBeFalse()
        ->and($a->isDocument())->toBeTrue();
});
