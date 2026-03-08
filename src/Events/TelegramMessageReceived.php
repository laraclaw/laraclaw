<?php

namespace LaraClaw\Events;

use Telegram\Bot\Api;
use Telegram\Bot\Objects\Message;

/**
 * Fired when a message is received from Telegram.
 */
class TelegramMessageReceived
{
    public function __construct(
        public readonly Message $message,
        public readonly Api $bot,
    ) {}
}
