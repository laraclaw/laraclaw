<?php

namespace LaraClaw\Listeners;

use LaraClaw\Channels\TelegramChannel;
use LaraClaw\Events\TelegramMessageReceived;
use LaraClaw\Jobs\ProcessMessage;

/**
 * Handles incoming Telegram messages and dispatches the ProcessMessage job.
 */
class TelegramListener
{
    /**
     * Parse the raw Telegram event and dispatch it for processing.
     */
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
