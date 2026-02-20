<?php

namespace LaraClaw\Handlers;

use LaraClaw\Channels\TelegramChannel;
use LaraClaw\Jobs\ProcessMessage;
use Illuminate\Support\Facades\Redis;
use SergiX44\Nutgram\Nutgram;

class Telegram
{
    public function __invoke(Nutgram $bot): void
    {
        if (! config('laraclaw.telegram.enabled')) {
            return;
        }

        $message = $bot->message();
        $text = $message->text ?? $message->caption ?? null;
        $identifier = "telegram:{$message->chat->id}";

        // If a tool is waiting for confirmation, push the reply and return early
        if (Redis::exists("awaiting_confirm:{$identifier}")) {
            if ($text !== null) {
                Redis::rpush("confirm:{$identifier}", $text);
            }

            return;
        }

        $channel = TelegramChannel::fromMessage($message, $bot);

        if (blank($channel->text()) && $channel->attachments()->isEmpty()) {
            return;
        }

        ProcessMessage::dispatch($channel);
    }
}
