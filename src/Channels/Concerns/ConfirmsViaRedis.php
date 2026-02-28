<?php

namespace LaraClaw\Channels\Concerns;

use Illuminate\Support\Facades\Redis;

trait ConfirmsViaRedis
{
    public static function intercept(string $identifier, ?string $text): bool
    {
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
        $identifier = $this->confirmIdentifier();
        $awaitingKey = "awaiting_confirm:{$identifier}";
        $confirmKey = "confirm:{$identifier}";

        // Signal to the handler that the next message is a confirmation reply
        Redis::set($awaitingKey, 1, 'EX', $timeout);

        // Clear any stale replies
        Redis::del($confirmKey);

        // Prompt the user
        $this->send("⚠️ {$message} Reply 'Yes' to confirm.");

        // Block until the handler pushes a reply or we time out.
        // Use the laraclaw-blocking connection (read_write_timeout = -1) so Predis
        // doesn't throw a TimeoutException before blpop returns naturally.
        $reply = Redis::connection('laraclaw-blocking')->blpop($confirmKey, $timeout);

        // Clean up the awaiting flag
        Redis::del($awaitingKey);

        return $reply && strtolower($reply[1]) === 'yes';
    }

    protected function confirmIdentifier(): string
    {
        return $this->identifier();
    }
}
