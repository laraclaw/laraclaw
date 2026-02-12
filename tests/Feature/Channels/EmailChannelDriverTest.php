<?php

use App\Channels\DTOs\AttachmentType;
use App\Channels\EmailChannelDriver;
use App\Mail\ChannelReply;
use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\Attachment as ImapAttachment;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Support\Facades\Mail;

function makeEmailMessage(array $overrides = []): MessageInterface
{
    $from = new Address(
        $overrides['from_email'] ?? 'sender@example.com',
        $overrides['from_name'] ?? 'Test Sender',
    );

    $message = Mockery::mock(MessageInterface::class);
    $message->shouldReceive('from')->andReturn($from);
    $message->shouldReceive('subject')->andReturn($overrides['subject'] ?? 'Test Subject');
    $message->shouldReceive('text')->andReturn(array_key_exists('text', $overrides) ? $overrides['text'] : 'Hello from email');
    $message->shouldReceive('html')->andReturn(array_key_exists('html', $overrides) ? $overrides['html'] : null);
    $message->shouldReceive('messageId')->andReturn(array_key_exists('message_id', $overrides) ? $overrides['message_id'] : '<abc123@example.com>');
    $message->shouldReceive('attachments')->andReturn($overrides['attachments'] ?? []);

    return $message;
}

it('creates from an email message', function () {
    $driver = EmailChannelDriver::fromMessage(makeEmailMessage());

    expect($driver->platform())->toBe('email')
        ->and($driver->conversationId())->toBe('<abc123@example.com>')
        ->and($driver->senderId())->toBe('sender@example.com')
        ->and($driver->text())->toBe('Hello from email')
        ->and($driver->attachments())->toBeEmpty();
});

it('falls back to stripped html when no text part', function () {
    $message = makeEmailMessage([
        'text' => null,
        'html' => '<p>Hello <strong>world</strong></p>',
    ]);

    $driver = EmailChannelDriver::fromMessage($message);

    expect($driver->text())->toBe('Hello world');
});

it('handles messages with no text or html', function () {
    $message = makeEmailMessage([
        'text' => null,
        'html' => null,
    ]);

    $driver = EmailChannelDriver::fromMessage($message);

    expect($driver->text())->toBeNull();
});

it('is serializable', function () {
    $driver = EmailChannelDriver::fromMessage(makeEmailMessage());
    $unserialized = unserialize(serialize($driver));

    expect($unserialized->platform())->toBe('email')
        ->and($unserialized->conversationId())->toBe('<abc123@example.com>')
        ->and($unserialized->senderId())->toBe('sender@example.com')
        ->and($unserialized->text())->toBe('Hello from email');
});

it('replies via laravel mail with a mailable', function () {
    Mail::fake();

    $driver = EmailChannelDriver::fromMessage(makeEmailMessage());

    $driver->reply('pong');

    Mail::assertSent(ChannelReply::class, function (ChannelReply $mail) {
        return $mail->hasTo('sender@example.com')
            && $mail->hasSubject('Re: Test Subject')
            && $mail->body === 'pong';
    });
});

it('downloads email attachments', function () {
    Storage::fake('local');

    config([
        'laraclaw.attachments.disk' => 'local',
        'laraclaw.attachments.path' => 'laraclaw/attachments',
    ]);

    $stream = Mockery::mock(\Psr\Http\Message\StreamInterface::class);
    $stream->shouldReceive('getContents')->andReturn('fake-pdf-content');

    $imapAttachment = new ImapAttachment(
        filename: 'report.pdf',
        contentId: null,
        contentType: 'application/pdf',
        contentDisposition: 'attachment',
        contentStream: $stream,
    );

    $message = makeEmailMessage([
        'attachments' => [$imapAttachment],
    ]);

    $driver = EmailChannelDriver::fromMessage($message);

    expect($driver->attachments())->toHaveCount(1);

    $attachment = $driver->attachments()->first();
    expect($attachment->type)->toBe(AttachmentType::Document)
        ->and($attachment->mimeType)->toBe('application/pdf')
        ->and($attachment->filename)->toBe('report.pdf')
        ->and($attachment->size)->toBe(strlen('fake-pdf-content'));

    Storage::disk('local')->assertExists($attachment->path);
});

it('uses sender email as conversation id when no message id', function () {
    $message = makeEmailMessage(['message_id' => null]);

    $driver = EmailChannelDriver::fromMessage($message);

    expect($driver->conversationId())->toBe('sender@example.com');
});
