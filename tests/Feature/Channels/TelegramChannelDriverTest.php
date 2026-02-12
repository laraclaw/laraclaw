<?php

use App\Channels\DTOs\AttachmentType;
use App\Channels\TelegramChannelDriver;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Media\Document;
use SergiX44\Nutgram\Telegram\Types\Media\File;
use SergiX44\Nutgram\Telegram\Types\Media\PhotoSize;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use SergiX44\Nutgram\Telegram\Types\User\User;

function makeTelegramMessage(array $overrides = []): Message
{
    $message = new Message;
    $message->message_id = 1;

    $chat = new Chat;
    $chat->id = $overrides['chat_id'] ?? 123;
    $message->chat = $chat;

    $from = new User;
    $from->id = $overrides['user_id'] ?? 456;
    $from->is_bot = false;
    $from->first_name = 'Test';
    $message->from = $from;

    $message->text = $overrides['text'] ?? 'hello bot';
    $message->date = time();

    return $message;
}

it('creates from a telegram message', function () {
    $bot = Mockery::mock(Nutgram::class);
    $message = makeTelegramMessage();

    $driver = TelegramChannelDriver::fromMessage($message, $bot);

    expect($driver->platform())->toBe('telegram')
        ->and($driver->conversationId())->toBe('123')
        ->and($driver->senderId())->toBe('456')
        ->and($driver->text())->toBe('hello bot')
        ->and($driver->attachments())->toBeEmpty();
});

it('handles messages with no text', function () {
    $bot = Mockery::mock(Nutgram::class);
    $message = makeTelegramMessage(['text' => null]);
    $message->text = null;

    $driver = TelegramChannelDriver::fromMessage($message, $bot);

    expect($driver->text())->toBeNull();
});

it('uses caption as text for media messages', function () {
    $bot = Mockery::mock(Nutgram::class);
    $message = makeTelegramMessage();
    $message->text = null;
    $message->caption = 'check this photo';

    $driver = TelegramChannelDriver::fromMessage($message, $bot);

    expect($driver->text())->toBe('check this photo');
});

it('is serializable', function () {
    $bot = Mockery::mock(Nutgram::class);
    $message = makeTelegramMessage();

    $driver = TelegramChannelDriver::fromMessage($message, $bot);
    $unserialized = unserialize(serialize($driver));

    expect($unserialized->platform())->toBe('telegram')
        ->and($unserialized->conversationId())->toBe('123')
        ->and($unserialized->senderId())->toBe('456')
        ->and($unserialized->text())->toBe('hello bot');
});

it('downloads photo attachments', function () {
    Storage::fake('local');

    config([
        'laraclaw.attachments.disk' => 'local',
        'laraclaw.attachments.path' => 'laraclaw/attachments',
    ]);

    $file = Mockery::mock(File::class)->makePartial();
    $file->file_id = 'file123';
    $file->file_path = 'photos/photo.jpg';
    $file->shouldReceive('save')->once()->andReturnUsing(function (string $path) {
        file_put_contents($path, 'fake-photo-content');

        return true;
    });

    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('getFile')->with('file123')->andReturn($file);

    $message = makeTelegramMessage();
    $message->text = null;

    $photo = new PhotoSize;
    $photo->file_id = 'file123';
    $photo->file_unique_id = 'unique123';
    $photo->width = 800;
    $photo->height = 600;
    $photo->file_size = 2048;
    $message->photo = [$photo];

    $driver = TelegramChannelDriver::fromMessage($message, $bot);

    expect($driver->attachments())->toHaveCount(1);

    $attachment = $driver->attachments()->first();
    expect($attachment->type)->toBe(AttachmentType::Image)
        ->and($attachment->mimeType)->toBe('image/jpeg')
        ->and($attachment->size)->toBe(2048);

    Storage::disk('local')->assertExists($attachment->path);
});

it('downloads document attachments', function () {
    Storage::fake('local');

    config([
        'laraclaw.attachments.disk' => 'local',
        'laraclaw.attachments.path' => 'laraclaw/attachments',
    ]);

    $file = Mockery::mock(File::class)->makePartial();
    $file->file_id = 'doc456';
    $file->file_path = 'documents/report.pdf';
    $file->shouldReceive('save')->once()->andReturnUsing(function (string $path) {
        file_put_contents($path, 'fake-pdf-content');

        return true;
    });

    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('getFile')->with('doc456')->andReturn($file);

    $message = makeTelegramMessage();

    $doc = new Document;
    $doc->file_id = 'doc456';
    $doc->file_unique_id = 'unique456';
    $doc->file_name = 'report.pdf';
    $doc->mime_type = 'application/pdf';
    $doc->file_size = 4096;
    $message->document = $doc;

    $driver = TelegramChannelDriver::fromMessage($message, $bot);

    expect($driver->attachments())->toHaveCount(1);

    $attachment = $driver->attachments()->first();
    expect($attachment->type)->toBe(AttachmentType::Document)
        ->and($attachment->mimeType)->toBe('application/pdf')
        ->and($attachment->filename)->toBe('report.pdf')
        ->and($attachment->size)->toBe(4096);

    Storage::disk('local')->assertExists($attachment->path);
});

it('replies via nutgram', function () {
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('sendMessage')
        ->once()
        ->withSomeOfArgs('pong');

    $this->app->instance(Nutgram::class, $bot);

    $message = makeTelegramMessage();
    $driver = TelegramChannelDriver::fromMessage($message, $bot);

    $driver->reply('pong');
});
