<?php

namespace LaraClaw\Handlers;

use LaraClaw\Channels\EmailChannel;
use LaraClaw\Jobs\ProcessMessage;
use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use Illuminate\Support\Facades\Redis;

class Email
{
    public function __invoke(MessageReceived $event): void
    {
        if (! config('laraclaw.email.enabled')) {
            return;
        }

        $message = $event->message;

        // Prevent loops — ignore emails from ourselves
        $botEmail = config('imap.mailboxes.'.config('laraclaw.email.mailbox', 'default').'.username');
        $fromEmail = $message->from()?->email() ?? 'unknown';

        if ($fromEmail === $botEmail) {
            return;
        }

        $identifier = "email:{$fromEmail}";

        // If a tool is waiting for confirmation, push the reply and return early
        if (Redis::exists("awaiting_confirm:{$identifier}")) {
            $text = $message->text();
            if ($text !== null) {
                Redis::rpush("confirm:{$identifier}", $text);
            }

            return;
        }

        $channel = EmailChannel::fromMessage($message);

        if (blank($channel->text()) && $channel->attachments()->isEmpty()) {
            return;
        }

        ProcessMessage::dispatch($channel);
    }
}
