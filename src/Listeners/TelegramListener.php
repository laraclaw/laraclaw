<?php

namespace LaraClaw\Listeners;

use Illuminate\Support\Facades\Redis;
use LaraClaw\Channels\TelegramChannel;
use LaraClaw\Events\TelegramMessageReceived;
use LaraClaw\Jobs\ProcessMessage;

class TelegramListener
{
    public function __invoke(TelegramMessageReceived $event): void
    {
        $message = $event->message;
        $text = $message->text ?? $message->caption ?? null;
        $identifier = "telegram:{$message->chat->id}";

        // If a tool is waiting for confirmation, push the reply and return early
        if (Redis::exists("awaiting_confirm:{$identifier}")) {
            if ($text !== null) {
                Redis::rpush("confirm:{$identifier}", $text);
            }

            return;
        }

        $channel = TelegramChannel::fromMessage($message, $event->bot);

        if (blank($channel->text()) && $channel->attachments()->isEmpty()) {
            return;
        }

        if (! $channel->shouldRespond()) {
            return;
        }

        ProcessMessage::dispatch($channel);
    }
}
