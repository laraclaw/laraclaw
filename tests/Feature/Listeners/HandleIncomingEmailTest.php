<?php

use App\Jobs\ProcessMessage;
use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Support\Facades\Queue;

function makeIncomingEmail(string $fromEmail = 'user@example.com'): MessageInterface
{
    $from = new Address($fromEmail, 'Test User');

    $message = Mockery::mock(MessageInterface::class);
    $message->shouldReceive('from')->andReturn($from);
    $message->shouldReceive('subject')->andReturn('Test');
    $message->shouldReceive('text')->andReturn('Hello');
    $message->shouldReceive('html')->andReturn(null);
    $message->shouldReceive('messageId')->andReturn('<msg@example.com>');
    $message->shouldReceive('attachments')->andReturn([]);

    return $message;
}

it('dispatches process message job for incoming email', function () {
    Queue::fake();

    config([
        'laraclaw.channels.email.enabled' => true,
        'laraclaw.channels.email.mailbox' => 'default',
        'imap.mailboxes.default.username' => 'bot@example.com',
    ]);

    event(new MessageReceived(makeIncomingEmail()));

    Queue::assertPushed(ProcessMessage::class);
});

it('ignores emails when channel is disabled', function () {
    Queue::fake();

    config(['laraclaw.channels.email.enabled' => false]);

    event(new MessageReceived(makeIncomingEmail()));

    Queue::assertNotPushed(ProcessMessage::class);
});

it('ignores emails from the bot itself', function () {
    Queue::fake();

    config([
        'laraclaw.channels.email.enabled' => true,
        'laraclaw.channels.email.mailbox' => 'default',
        'imap.mailboxes.default.username' => 'bot@example.com',
    ]);

    event(new MessageReceived(makeIncomingEmail('bot@example.com')));

    Queue::assertNotPushed(ProcessMessage::class);
});
