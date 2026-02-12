<?php

namespace App\Telegram\Handlers;

use App\Channels\TelegramChannelDriver;
use App\Jobs\ProcessMessage;
use SergiX44\Nutgram\Nutgram;

class HandleMessage
{
    public function __invoke(Nutgram $bot): void
    {
        $message = $bot->message();

        if (! $message) {
            return;
        }

        $driver = TelegramChannelDriver::fromMessage($message, $bot);

        ProcessMessage::dispatch($driver);
    }
}
