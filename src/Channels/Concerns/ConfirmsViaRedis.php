<?php

namespace LaraClaw\Channels\Concerns;

use Illuminate\Support\Facades\Redis;

trait ConfirmsViaRedis
{
    public function intercept(?string $text): bool
    {
        $identifier = $this->identifier();

        if (! Redis::exists("awaiting_confirm:{$identifier}")) {
            return false;
        }

        if ($text !== null) {
            Redis::rpush("confirm:{$identifier}", $text);
        }

        return true;
    }

    public function confirm(string $message, int $timeout = 120): bool
    {
        $identifier = $this->identifier();
        $awaitingKey = "awaiting_confirm:{$identifier}";
        $confirmKey = "confirm:{$identifier}";

        // Signal to the handler that the next message is a confirmation reply
        Redis::set($awaitingKey, 1, 'EX', $timeout);

        // Clear any stale replies
        Redis::del($confirmKey);

        // Prompt the user
        $this->send("⚠️ {$message} Reply 'Yes' to confirm.");

        // Block until the listener pushes a reply into the confirm queue or we time
        // out. We use the laraclaw-blocking connection (read_write_timeout = -1)
        // so that Predis won't throw a TimeoutException before blpop returns.
        $reply = Redis::connection('laraclaw-blocking')->blpop($confirmKey, $timeout);

        // Clean up the awaiting flag
        Redis::del($awaitingKey);

        return $reply && strtolower($reply[1]) === 'yes';
    }
}
