<?php

use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Support\Facades\Log;
use LaraClaw\Enums\ConnectorType;
use LaraClaw\Models\UserAccount;

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
    return new MessageReceived($raw);
}

function registerEmailAccount(string $email): void
{
    $user = test()->createUser();

    UserAccount::create([
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

    app(LaraClaw\Listeners\EmailListener::class)(makeEvent($raw));

    Log::shouldHaveReceived('debug')
        ->once()
        ->withArgs(fn ($msg, $ctx) => $ctx['code'] === 'SELF_MESSAGE');
});

it('ignores senders without a registered account', function () {
    $raw = makeRawEmail('stranger@example.com');

    app(LaraClaw\Listeners\EmailListener::class)(makeEvent($raw));

    Log::shouldHaveReceived('debug')
        ->once()
        ->withArgs(fn ($msg, $ctx) => $ctx['code'] === 'UNREGISTERED_ACCOUNT');
});

it('rejects and logs a warning when DKIM/SPF authentication fails', function () {
    registerEmailAccount('allowed@example.com');

    $raw = makeRawEmail('allowed@example.com', 'Test', 'dkim=fail spf=fail');

    app(LaraClaw\Listeners\EmailListener::class)(makeEvent($raw));

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'DKIM or SPF'));
});

it('accepts emails that fail DKIM/SPF when verification is disabled', function () {
    config(['laraclaw.connectors.email.verify_sender_dkim_and_spf' => false]);
    registerEmailAccount('allowed@example.com');

    $raw = makeRawEmail('allowed@example.com', 'Test', 'dkim=fail spf=fail');

    app(LaraClaw\Listeners\EmailListener::class)(makeEvent($raw));

    Log::shouldNotHaveReceived('debug');
})->todo('assert agent is queued once agent queue testing is set up');

it('does nothing when the email connector is disabled', function () {
    config(['laraclaw.connectors.email.enabled' => false]);

    $raw = makeRawEmail('allowed@example.com');

    app(LaraClaw\Listeners\EmailListener::class)(makeEvent($raw));

    Log::shouldHaveReceived('debug')
        ->once()
        ->withArgs(fn ($msg, $ctx) => $ctx['code'] === 'CHANNEL_DISABLED');
});
