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
        $message = TelegramChannel::parseIncomingMessage($raw, $event->bot);

        if ($message->channel->intercept($message)) {
            return;
        }

        if (blank($message->text) && $message->attachments->isEmpty()) {
            return;
        }

        if ($message->isFromUnrecognizedAccount()) {
            return;
        }

        ProcessMessage::dispatch($message);
    }
}
