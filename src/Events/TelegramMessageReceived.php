<?php

namespace LaraClaw\Events;

use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

class TelegramMessageReceived
{
    public function __construct(
        public readonly Message $message,
        public readonly Nutgram $bot,
    ) {}
}
