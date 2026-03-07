<?php

use Illuminate\Support\Facades\Storage;
use LaraClaw\Services\Attachments;

beforeEach(function () {
    Storage::fake('local');
    $this->attachments = new Attachments;
});

it('writes and reads an inbound file', function () {
    $path = $this->attachments->inbound('uuid-1')->set('photo.jpg', 'image-data');

    expect($path)->toBe('inbound/uuid-1/photo.jpg');

    $content = $this->attachments->inbound('uuid-1')->get('photo.jpg');

    expect($content)->toBe('image-data');
});

it('writes and reads an outbound file', function () {
    $path = $this->attachments->outbound('uuid-2')->set('reply.txt', 'hello');

    expect($path)->toBe('outbound/uuid-2/reply.txt');
});

it('returns all files as Attachment DTOs', function () {
    $this->attachments->outbound('uuid-3')->set('a.txt', 'aaa');
    $this->attachments->outbound('uuid-3')->set('b.txt', 'bbb');

    $all = $this->attachments->outbound('uuid-3')->getAll();

    expect($all)->toHaveCount(2)
        ->and($all->pluck('filename')->sort()->values()->all())->toBe(['a.txt', 'b.txt']);
});
