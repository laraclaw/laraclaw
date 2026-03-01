<?php

namespace LaraClaw\Events;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

/**
 * Fired when a message is received from Telegram via Nutgram.
 */
class TelegramMessageReceived
{
    public function __construct(
        public readonly Message $message,
        public readonly Nutgram $bot,
    ) {}
}
