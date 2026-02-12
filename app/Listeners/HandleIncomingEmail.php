<?php

namespace App\Listeners;

use App\Channels\EmailChannelDriver;
use App\Jobs\ProcessMessage;
use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;

class HandleIncomingEmail
{
    public function handle(MessageReceived $event): void
    {
        if (! config('laraclaw.channels.email.enabled')) {
            return;
        }

        $message = $event->message;

        // Prevent loops — ignore emails from ourselves
        $botEmail = config('imap.mailboxes.' . config('laraclaw.channels.email.mailbox', 'default') . '.username');

        if ($message->from()?->email() === $botEmail) {
            return;
        }

        $driver = EmailChannelDriver::fromMessage($message);

        ProcessMessage::dispatch($driver);
    }
}
