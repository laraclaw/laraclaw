<?php

namespace LaraClaw\Listeners;

use LaraClaw\Channels\TelegramChannel;
use LaraClaw\Events\TelegramMessageReceived;
use LaraClaw\Jobs\ProcessMessage;

class TelegramListener
{
    public function __invoke(TelegramMessageReceived $event): void
    {
        $raw = $event->message;
        $text = $raw->text ?? $raw->caption ?? null;
        if (TelegramChannel::intercept(TelegramChannel::identifierFor($raw->chat->id), $text)) {
            return;
        }

        $message = TelegramChannel::from($raw, $event->bot);

        if (blank($message->text) && $message->attachments->isEmpty()) {
            return;
        }

        if ($message->shouldBeIgnored()) {
            return;
        }

        ProcessMessage::dispatch($message);
    }
}
