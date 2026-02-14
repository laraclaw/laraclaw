<?php

use App\Channels\SlackChannelDriver;
use Illuminate\Support\Facades\Http;

it('creates from a slack event', function () {
    $event = [
        'type' => 'message',
        'user' => 'U123',
        'text' => 'hello bot',
        'ts' => '1234567890.123456',
        'channel' => 'C456',
    ];

    $driver = SlackChannelDriver::fromEvent($event);

    expect($driver->platform())->toBe('slack')
        ->and($driver->conversationId())->toBe('C456')
        ->and($driver->senderId())->toBe('U123')
        ->and($driver->text())->toBe('hello bot')
        ->and($driver->attachments())->toBeEmpty();
});

it('handles messages with no text', function () {
    $event = [
        'type' => 'message',
        'user' => 'U123',
        'ts' => '1234567890.123456',
        'channel' => 'C456',
    ];

    $driver = SlackChannelDriver::fromEvent($event);

    expect($driver->text())->toBeNull();
});

it('replies via slack api', function () {
    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
    ]);

    config(['laraclaw.channels.slack.bot_token' => 'xoxb-test-token']);

    $driver = SlackChannelDriver::fromEvent([
        'type' => 'message',
        'user' => 'U123',
        'text' => 'ping',
        'ts' => '1234567890.123456',
        'channel' => 'C456',
    ]);

    $driver->reply('pong');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://slack.com/api/chat.postMessage'
            && $request['channel'] === 'C456'
            && $request['text'] === 'pong'
            && $request['thread_ts'] === '1234567890.123456'
            && $request->hasHeader('Authorization', 'Bearer xoxb-test-token');
    });
});

it('is serializable', function () {
    $driver = SlackChannelDriver::fromEvent([
        'type' => 'message',
        'user' => 'U123',
        'text' => 'test',
        'ts' => '1234567890.123456',
        'channel' => 'C456',
    ]);

    $unserialized = unserialize(serialize($driver));

    expect($unserialized->platform())->toBe('slack')
        ->and($unserialized->conversationId())->toBe('C456')
        ->and($unserialized->senderId())->toBe('U123')
        ->and($unserialized->text())->toBe('test');
});

it('downloads file attachments', function () {
    Http::fake([
        'files.slack.com/*' => Http::response('fake-file-content'),
    ]);

    config([
        'laraclaw.channels.slack.bot_token' => 'xoxb-test-token',
        'laraclaw.attachments.disk' => 'local',
        'laraclaw.attachments.path' => 'laraclaw/attachments',
    ]);

    Storage::fake('local');

    $driver = SlackChannelDriver::fromEvent([
        'type' => 'message',
        'user' => 'U123',
        'text' => 'check this',
        'ts' => '1234567890.123456',
        'channel' => 'C456',
        'files' => [
            [
                'id' => 'F123',
                'name' => 'photo.png',
                'mimetype' => 'image/png',
                'url_private_download' => 'https://files.slack.com/files-pri/T123-F123/download/photo.png',
                'size' => 1024,
            ],
        ],
    ]);

    expect($driver->attachments())->toHaveCount(1);

    $attachment = $driver->attachments()->first();
    expect($attachment->type)->toBe(\App\Channels\DTOs\AttachmentType::Image)
        ->and($attachment->mimeType)->toBe('image/png')
        ->and($attachment->filename)->toBe('photo.png')
        ->and($attachment->size)->toBe(1024);

    Storage::disk('local')->assertExists($attachment->path);
});

it('swallows typing indicator failures', function () {
    Log::spy();
    Http::fake(fn () => throw new RuntimeException('Connection refused'));

    config(['laraclaw.channels.slack.bot_token' => 'xoxb-test-token']);

    $driver = SlackChannelDriver::fromEvent([
        'type' => 'message',
        'user' => 'U123',
        'text' => 'ping',
        'ts' => '1234567890.123456',
        'channel' => 'C456',
    ]);

    $driver->sendTypingIndicator();

    Log::shouldHaveReceived('warning')->with('Slack typing indicator failed', Mockery::any());
});
