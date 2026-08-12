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

it('leaves other audio extensions untouched', function () {
    $message = new TelegramMessage([
        'chat' => ['id' => 123],
        'audio' => ['file_id' => 'abc', 'mime_type' => 'audio/mpeg', 'file_name' => 'song.mp3'],
    ]);

    $incoming = Telegram::createIncomingMessageFrom($message, botServing('music/song.mp3'), app(Attachments::class));

    expect($incoming->attachments[0]->path)->toEndWith('song.mp3');
});
