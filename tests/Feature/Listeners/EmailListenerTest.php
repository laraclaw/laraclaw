<?php

use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use LaraClaw\Jobs\ProcessMessage;
use LaraClaw\Listeners\EmailListener;

function makeRawEmail(string $from, string $subject = 'Hello', string $authResults = 'dkim=pass spf=pass'): MessageInterface
{
    $header = Mockery::mock(\ZBateson\MailMimeParser\Header\IHeader::class);
    $header->allows('getRawValue')->andReturn($authResults);

    $raw = Mockery::mock(MessageInterface::class);
    $raw->allows('from')->andReturn(new Address($from, ''));
    $raw->allows('subject')->andReturn($subject);
    $raw->allows('header')->with('Authentication-Results')->andReturn($header);
    $raw->allows('header')->withAnyArgs()->andReturn(null);

    // Minimal stubs needed by EmailChannel::parseIncomingMessage()
    $raw->allows('messageId')->andReturn('<msg-id@example.com>');
    $raw->allows('uid')->andReturn(1);
    $raw->allows('text')->andReturn('Email body');
    $raw->allows('html')->andReturn(null);
    $raw->allows('references')->andReturn(null);
    $raw->allows('inReplyTo')->andReturn(null);
    $raw->allows('attachments')->andReturn([]);

    return $raw;
}

function makeEvent(MessageInterface $raw): MessageReceived
{
    return new MessageReceived($raw);
}

beforeEach(function () {
    Queue::fake();
    Redis::spy();
    config(['laraclaw.channels.email.enabled' => true]);
    config(['laraclaw.channels.email.sender_allow_list' => ['allowed@example.com']]);
    config(['laraclaw.channels.email.verify_sender_dkim_and_spf' => true]);
    config(['imap.mailboxes.default.username' => 'bot@example.com']);
});

it('dispatches ProcessMessage for a valid allowed sender', function () {
    $raw = makeRawEmail('allowed@example.com');
    $listener = new EmailListener;

    $listener(makeEvent($raw));

    Queue::assertPushed(ProcessMessage::class);
});

it('ignores emails from the bot itself to prevent loops', function () {
    config(['imap.mailboxes.default.username' => 'bot@example.com']);

    $raw = makeRawEmail('bot@example.com');
    $listener = new EmailListener;

    $listener(makeEvent($raw));

    Queue::assertNotPushed(ProcessMessage::class);
});

it('ignores senders not on the allow list', function () {
    $raw = makeRawEmail('stranger@example.com');
    $listener = new EmailListener;

    $listener(makeEvent($raw));

    Queue::assertNotPushed(ProcessMessage::class);
});

it('blocks all senders when the allow list is empty', function () {
    config(['laraclaw.channels.email.sender_allow_list' => []]);

    $raw = makeRawEmail('allowed@example.com');
    $listener = new EmailListener;

    $listener(makeEvent($raw));

    Queue::assertNotPushed(ProcessMessage::class);
});

it('rejects and logs a warning when DKIM/SPF authentication fails', function () {
    Log::spy();

    $raw = makeRawEmail('allowed@example.com', 'Test', 'dkim=fail spf=fail');
    $listener = new EmailListener;

    $listener(makeEvent($raw));

    Queue::assertNotPushed(ProcessMessage::class);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($msg) => str_contains($msg, 'DKIM/SPF'));
});

it('accepts emails that fail DKIM/SPF when verification is disabled', function () {
    config(['laraclaw.channels.email.verify_sender_dkim_and_spf' => false]);

    $raw = makeRawEmail('allowed@example.com', 'Test', 'dkim=fail spf=fail');
    $listener = new EmailListener;

    $listener(makeEvent($raw));

    Queue::assertPushed(ProcessMessage::class);
});

it('does nothing when the email channel is disabled', function () {
    config(['laraclaw.channels.email.enabled' => false]);

    $raw = makeRawEmail('allowed@example.com');
    $listener = new EmailListener;

    $listener(makeEvent($raw));

    Queue::assertNotPushed(ProcessMessage::class);
});
