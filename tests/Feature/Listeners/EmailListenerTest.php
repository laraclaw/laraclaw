<?php

use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use DirectoryTree\ImapEngine\Laravel\ImapManager;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Account;
use Laravel\Ai\Jobs\InvokeAgent;

function makeRawEmail(string $from, string $subject = 'Hello', string $authResults = 'dkim=pass spf=pass'): MessageInterface
{
    $header = Mockery::mock(\ZBateson\MailMimeParser\Header\IHeader::class);
    $header->allows('getRawValue')->andReturn($authResults);

    $raw = Mockery::mock(MessageInterface::class);
    $raw->allows('from')->andReturn(new Address($from, ''));
    $raw->allows('subject')->andReturn($subject);
    $raw->allows('header')->with('Authentication-Results')->andReturn($header);
    $raw->allows('header')->withAnyArgs()->andReturn(null);
    $raw->allows('messageId')->andReturn('<msg-id@example.com>');
    $raw->allows('uid')->andReturn(1);
    $raw->allows('text')->andReturn('Email body');
    $raw->allows('html')->andReturn(null);
    $raw->allows('attachments')->andReturn([]);

    return $raw;
}

function makeEvent(MessageInterface $raw): MessageReceived
{
    return new MessageReceived($raw, 'default');
}

function registerEmailAccount(string $email): void
{
    $user = test()->createUser();

    Account::create([
        'user_id' => $user->getAuthIdentifier(),
        'connector' => ConnectorType::Email,
        'account' => $email,
    ]);
}

beforeEach(function () {
    Log::spy();
    config(['laraclaw.connectors.email.enabled' => true]);
    config(['laraclaw.connectors.email.verify_sender_dkim_and_spf' => true]);
    config(['imap.mailboxes.default.username' => 'bot@example.com']);
});

it('ignores emails from the bot itself to prevent loops', function () {
    $raw = makeRawEmail('bot@example.com');

    app(Laraclaw\Listeners\EmailListener::class)(makeEvent($raw));

    Log::shouldHaveReceived('debug')
        ->once()
        ->withArgs(fn ($msg, $ctx) => $ctx['code'] === 'SELF_MESSAGE');
});

it('ignores senders without a registered account', function () {
    $raw = makeRawEmail('stranger@example.com');

    app(Laraclaw\Listeners\EmailListener::class)(makeEvent($raw));

    Log::shouldHaveReceived('debug')
        ->once()
        ->withArgs(fn ($msg, $ctx) => $ctx['code'] === 'UNREGISTERED_ACCOUNT');
});

it('rejects and logs a warning when DKIM/SPF authentication fails', function () {
    registerEmailAccount('allowed@example.com');

    $raw = makeRawEmail('allowed@example.com', 'Test', 'dkim=fail spf=fail');

    app(Laraclaw\Listeners\EmailListener::class)(makeEvent($raw));

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'DKIM or SPF'));
});

it('accepts emails that fail DKIM/SPF when verification is disabled', function () {
    config(['laraclaw.connectors.email.verify_sender_dkim_and_spf' => false]);
    registerEmailAccount('allowed@example.com');

    $raw = makeRawEmail('allowed@example.com', 'Test', 'dkim=fail spf=fail');

    app(Laraclaw\Listeners\EmailListener::class)(makeEvent($raw));

    Log::shouldNotHaveReceived('debug');
})->todo('assert agent is queued once agent queue testing is set up');

it('does nothing when the email connector is disabled', function () {
    config(['laraclaw.connectors.email.enabled' => false]);

    $raw = makeRawEmail('allowed@example.com');

    app(Laraclaw\Listeners\EmailListener::class)(makeEvent($raw));

    Log::shouldHaveReceived('debug')
        ->once()
        ->withArgs(fn ($msg, $ctx) => $ctx['code'] === 'CHANNEL_DISABLED');
});

function mockImapInbox(): MessageInterface
{
    $stored = Mockery::mock(MessageInterface::class);

    $query = Mockery::mock(MessageQueryInterface::class);
    $query->allows('find')->andReturn($stored);

    $inbox = Mockery::mock(FolderInterface::class);
    $inbox->allows('messages')->andReturn($query);

    $mailbox = Mockery::mock(MailboxInterface::class);
    $mailbox->allows('inbox')->andReturn($inbox);

    $manager = Mockery::mock(ImapManager::class);
    $manager->allows('mailbox')->andReturn($mailbox);

    app()->instance(ImapManager::class, $manager);

    return $stored;
}

it('marks the email seen exactly once after the agent job is queued', function () {
    Queue::fake();
    registerEmailAccount('allowed@example.com');

    $stored = mockImapInbox();
    $stored->expects('flag')->once()->with(ImapFlag::Seen, '+');

    app(Laraclaw\Listeners\EmailListener::class)(makeEvent(makeRawEmail('allowed@example.com')));

    Queue::assertPushed(InvokeAgent::class, 1);
});

it('leaves the email unseen when the agent job cannot be queued', function () {
    registerEmailAccount('allowed@example.com');

    // No such connection is configured, so releasing the pending dispatch throws
    config(['queue.default' => 'nonexistent']);

    $stored = mockImapInbox();
    $stored->expects('flag')->never();

    app(Laraclaw\Listeners\EmailListener::class)(makeEvent(makeRawEmail('allowed@example.com')));

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'failed to queue the agent'));
});

it('does not queue the agent twice for an email it already handed off', function () {
    Queue::fake();
    registerEmailAccount('allowed@example.com');

    $stored = mockImapInbox();
    $stored->allows('flag');

    $listener = app(Laraclaw\Listeners\EmailListener::class);

    $listener(makeEvent(makeRawEmail('allowed@example.com')));
    $listener(makeEvent(makeRawEmail('allowed@example.com')));

    Queue::assertPushed(InvokeAgent::class, 1);
});

it('gives up on an email that keeps failing and marks it seen', function () {
    config(['laraclaw.connectors.email.max_processing_attempts' => 1]);
    config(['queue.default' => 'nonexistent']);
    registerEmailAccount('allowed@example.com');

    $stored = mockImapInbox();
    $stored->expects('flag')->once()->with(ImapFlag::Seen, '+');

    $listener = app(Laraclaw\Listeners\EmailListener::class);

    $listener(makeEvent(makeRawEmail('allowed@example.com')));
    $listener(makeEvent(makeRawEmail('allowed@example.com')));

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($msg) => str_contains($msg, 'abandoned after repeated'));
});
