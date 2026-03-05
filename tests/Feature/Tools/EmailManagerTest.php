<?php

use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\FolderRepositoryInterface;
use DirectoryTree\ImapEngine\Laravel\ImapManager;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use LaraClaw\DTOs\Attachment;
use LaraClaw\DTOs\IncomingMessage;
use LaraClaw\Enums\ChannelType;
use LaraClaw\Tools\EmailManager;
use Laravel\Ai\Tools\Request;
use Symfony\Component\Mime\Email;

function emailRequest(array $data): Request
{
    $mock = Mockery::mock(Request::class);
    $mock->allows('offsetGet')->andReturnUsing(fn ($key) => $data[$key] ?? null);
    $mock->allows('offsetExists')->andReturnUsing(fn ($key) => array_key_exists($key, $data));

    return $mock;
}

function emailTool(?IncomingMessage $message = null): EmailManager
{
    $message ??= new IncomingMessage(
        text: 'test',
        channel: ChannelType::Telegram,
        key: 'user-123',
        isDirectMessage: true,
    );

    return new EmailManager($message, 'default');
}

// ── send ────────────────────────────────────────────────────────────────────

it('sends an email and returns a confirmation', function () {
    $result = emailTool()->handle(emailRequest([
        'operation' => 'send',
        'to' => ['to@example.com'],
        'subject' => 'Hello',
        'body' => 'World',
    ]));

    expect($result)->toContain('Email sent to to@example.com');
});

it('requires to for send', function () {
    $result = emailTool()->handle(emailRequest([
        'operation' => 'send',
        'subject' => 'Hello',
        'body' => 'World',
    ]));

    expect($result)->toContain('"to" parameter is required');
});

it('requires subject for send', function () {
    $result = emailTool()->handle(emailRequest([
        'operation' => 'send',
        'to' => ['to@example.com'],
        'body' => 'World',
    ]));

    expect($result)->toContain('"subject" parameter is required');
});

it('requires body for send', function () {
    $result = emailTool()->handle(emailRequest([
        'operation' => 'send',
        'to' => ['to@example.com'],
        'subject' => 'Hello',
    ]));

    expect($result)->toContain('"body" parameter is required');
});

it('attaches message attachments when sending an email', function () {
    Storage::fake('local');
    Storage::disk('local')->put('attachments/test/photo.jpg', 'fake-image-data');

    $attachment = new Attachment(
        path: 'attachments/test/photo.jpg',
        disk: 'local',
        mimeType: 'image/jpeg',
        filename: 'photo.jpg',
    );

    $message = new IncomingMessage(
        text: 'test',
        channel: ChannelType::Telegram,
        key: 'user-123',
        isDirectMessage: true,
        attachments: [$attachment],
    );

    $captured = null;
    Event::listen(MessageSending::class, function (MessageSending $e) use (&$captured) {
        $captured = $e->message;
    });

    $result = emailTool($message)->handle(emailRequest([
        'operation' => 'send',
        'to' => ['to@example.com'],
        'subject' => 'See attached',
        'body' => 'Here is the file.',
    ]));

    expect($result)->toContain('Email sent');
    expect($captured)->toBeInstanceOf(Email::class);

    $parts = $captured->getAttachments();
    expect($parts)->toHaveCount(1);
    expect($parts[0]->getFilename())->toBe('photo.jpg');
    expect($parts[0]->getMediaType() . '/' . $parts[0]->getMediaSubtype())->toBe('image/jpeg');
    expect($parts[0]->getBody())->toBe('fake-image-data');
});

it('attaches files passed explicitly via the attachments parameter', function () {
    Storage::fake('local');
    Storage::disk('local')->put('attachments/telegram/uuid/logo.png', 'logo-data');

    $captured = null;
    Event::listen(MessageSending::class, function (MessageSending $e) use (&$captured) {
        $captured = $e->message;
    });

    emailTool()->handle(emailRequest([
        'operation' => 'send',
        'to' => ['to@example.com'],
        'subject' => 'Here are the logos',
        'body' => 'See attached.',
        'attachments' => [
            ['disk' => 'local', 'path' => 'attachments/telegram/uuid/logo.png', 'filename' => 'logo.png', 'mime_type' => 'image/png'],
        ],
    ]));

    expect($captured)->toBeInstanceOf(Email::class);
    $parts = $captured->getAttachments();
    expect($parts)->toHaveCount(1);
    expect($parts[0]->getFilename())->toBe('logo.png');
    expect($parts[0]->getMediaType() . '/' . $parts[0]->getMediaSubtype())->toBe('image/png');
    expect($parts[0]->getBody())->toBe('logo-data');
});

it('sends without attachments when the message has none', function () {
    $captured = null;
    Event::listen(MessageSending::class, function (MessageSending $e) use (&$captured) {
        $captured = $e->message;
    });

    emailTool()->handle(emailRequest([
        'operation' => 'send',
        'to' => ['to@example.com'],
        'subject' => 'No files',
        'body' => 'Plain text.',
    ]));

    expect($captured)->toBeInstanceOf(Email::class);
    expect($captured->getAttachments())->toBeEmpty();
});

// ── reply ───────────────────────────────────────────────────────────────────

it('attaches message attachments when replying', function () {
    Storage::fake('local');
    Storage::disk('local')->put('attachments/test/doc.pdf', 'fake-pdf-data');

    config(['imap.mailboxes.default' => ['username' => null]]);

    $attachment = new Attachment(
        path: 'attachments/test/doc.pdf',
        disk: 'local',
        mimeType: 'application/pdf',
        filename: 'doc.pdf',
    );

    $message = new IncomingMessage(
        text: 'test',
        channel: ChannelType::Telegram,
        key: 'user-123',
        isDirectMessage: true,
        attachments: [$attachment],
    );

    $from = new Address('sender@example.com', 'Sender');

    $original = Mockery::mock(MessageInterface::class);
    $original->allows('replyTo')->andReturn(null);
    $original->allows('from')->andReturn($from);
    $original->allows('subject')->andReturn('Original subject');
    $original->allows('messageId')->andReturn('<original-id@example.com>');
    $original->allows('markAnswered');

    $query = Mockery::mock(MessageQueryInterface::class);
    $query->allows('withHeaders')->andReturnSelf();
    $query->allows('withBody')->andReturnSelf();
    $query->allows('find')->andReturn($original);

    $folder = Mockery::mock(FolderInterface::class);
    $folder->allows('messages')->andReturn($query);

    $folderRepo = Mockery::mock(FolderRepositoryInterface::class);
    $folderRepo->allows('findOrFail')->andReturn($folder);

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->allows('folders')->andReturn($folderRepo);

    $imapManager = Mockery::mock(ImapManager::class);
    $imapManager->allows('mailbox')->andReturn($mailbox);
    app()->instance(ImapManager::class, $imapManager);

    $captured = null;
    Event::listen(MessageSending::class, function (MessageSending $e) use (&$captured) {
        $captured = $e->message;
    });

    $result = emailTool($message)->handle(emailRequest([
        'operation' => 'reply',
        'uid' => 42,
        'body' => 'Here is the file.',
    ]));

    expect($result)->not->toContain('failed');
    expect($captured)->toBeInstanceOf(Email::class);

    $parts = $captured->getAttachments();
    expect($parts)->toHaveCount(1);
    expect($parts[0]->getFilename())->toBe('doc.pdf');
    expect($parts[0]->getMediaType() . '/' . $parts[0]->getMediaSubtype())->toBe('application/pdf');
    expect($parts[0]->getBody())->toBe('fake-pdf-data');
});
