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
        $raw = $event->message;
        $text = $raw->text ?? $raw->caption ?? null;
        $identifier = "telegram:{$raw->chat->id}";

        // If a tool is waiting for confirmation, push the reply and return early
        if (Redis::exists("awaiting_confirm:{$identifier}")) {
            if ($text !== null) {
                Redis::rpush("confirm:{$identifier}", $text);
            }

            return;
        }

        $message = TelegramChannel::from($raw, $event->bot);

        if (blank($message->text) && $message->attachments->isEmpty()) {
            return;
        }

        if (! $message->channel->shouldRespond($message->text)) {
            return;
        }

        ProcessMessage::dispatch($message);
    }
}
