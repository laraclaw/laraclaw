<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laraclaw\Connectors\Telegram;
use Laraclaw\Services\Attachments;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\File;
use Telegram\Bot\Objects\Message as TelegramMessage;

/**
 * Build a bot that hands back a file living at the given Telegram path.
 */
function botServing(string $filePath): Api
{
    $bot = Mockery::mock(Api::class);
    $bot->shouldReceive('getFile')->andReturn(new File(['file_path' => $filePath]));
    $bot->shouldReceive('getAccessToken')->andReturn('test-token');

    return $bot;
}

beforeEach(function () {
    Storage::fake('attachments');

    config([
        'laraclaw.filesystem.attachments_disk' => 'attachments',
        'laraclaw.filesystem.incoming_attachments_path' => 'inbound',
    ]);

    Http::fake(['api.telegram.org/*' => Http::response('audio-bytes')]);
});

it('stores a telegram voice note with an ogg extension', function () {
    // Telegram serves voice notes as .oga. The transcription API rejects that
    // extension even though the container is identical, so the whole reply died.
    $message = new TelegramMessage([
        'chat' => ['id' => 123],
        'voice' => ['file_id' => 'abc', 'mime_type' => 'audio/ogg'],
    ]);

    $incoming = Telegram::createIncomingMessageFrom($message, botServing('voice/file_1.oga'), app(Attachments::class));

    expect($incoming->attachments)->toHaveCount(1)
        ->and($incoming->attachments[0]->path)->toEndWith('.ogg')
        ->and($incoming->attachments[0]->path)->not->toEndWith('.oga');
});

it('reads the sender name off the message', function () {
    $message = new TelegramMessage([
        'chat' => ['id' => 123],
        'from' => ['id' => 9, 'first_name' => 'Noelia', 'last_name' => 'Rodriguez'],
        'text' => 'hola',
    ]);

    $incoming = Telegram::createIncomingMessageFrom($message, botServing('x'), app(Attachments::class));

    expect($incoming->senderName)->toBe('Noelia Rodriguez');
});

it('falls back to the username when the sender has no first name', function () {
    $message = new TelegramMessage([
        'chat' => ['id' => 123],
        'from' => ['id' => 9, 'username' => 'nuly'],
        'text' => 'hola',
    ]);

    $incoming = Telegram::createIncomingMessageFrom($message, botServing('x'), app(Attachments::class));

    expect($incoming->senderName)->toBe('nuly');
});

it('reports no sender name when the message has no sender', function () {
    $message = new TelegramMessage(['chat' => ['id' => 123], 'text' => 'hola']);

    $incoming = Telegram::createIncomingMessageFrom($message, botServing('x'), app(Attachments::class));

    expect($incoming->senderName)->toBeNull();
});

it('carries the sender name through job serialization', function () {
    // Queued jobs thaw the DTO through __unserialize, which drops anything the
    // explicit key list forgets.
    $message = new TelegramMessage([
        'chat' => ['id' => 123],
        'from' => ['id' => 9, 'first_name' => 'Nuly'],
        'text' => 'hola',
    ]);

    $incoming = Telegram::createIncomingMessageFrom($message, botServing('x'), app(Attachments::class));

    expect(unserialize(serialize($incoming))->senderName)->toBe('Nuly');
});

it('leaves other audio extensions untouched', function () {
    $message = new TelegramMessage([
        'chat' => ['id' => 123],
        'audio' => ['file_id' => 'abc', 'mime_type' => 'audio/mpeg', 'file_name' => 'song.mp3'],
    ]);

    $incoming = Telegram::createIncomingMessageFrom($message, botServing('music/song.mp3'), app(Attachments::class));

    expect($incoming->attachments[0]->path)->toEndWith('song.mp3');
});
