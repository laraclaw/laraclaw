<?php

namespace Laraclaw\Events;

use Telegram\Bot\Api;
use Telegram\Bot\Objects\Message;

/**
 * Fired when a message is received from Telegram.
 */
class TelegramMessageReceived
{
    /**
     * Capture the inbound Telegram message and the bot client that received it.
     */
    public function __construct(
        public readonly Message $message,
        public readonly Api $bot,
    ) {}
}
