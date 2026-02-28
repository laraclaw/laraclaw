<?php

namespace LaraClaw\Channels\Concerns;

use Illuminate\Support\Facades\Redis;

trait ChecksRedisForConfirmations
{
    private const AWAITING_KEY = 'awaiting_confirm:';

    private const CONFIRM_KEY = 'confirm:';

    /**
     * Intercept an incoming message if a confirmation is pending for this channel.
     *
     * Returns true if the message was consumed and should not be processed further.
     *
     * @param  string|null  $text
     * @return bool
     */
    public function intercept(?string $text): bool
    {
        $identifier = $this->name . ':' . $this->conversationKey();

        // If no confirmation is pending for this conversation, the incoming message
        // is not a reply to a pending confirm prompt and we should not intercept
        // it. Returning false signals that the message can proceed as normal.
        if (! Redis::exists(self::AWAITING_KEY . $identifier)) {
            return false;
        }

        // A confirmation is pending, so we push the message text into the queue for
        // `confirm` blpop to pick it up. A `null` text (e.g. a file-only message) is
        // silently ignored; we still return true to suppress normal handling.
        if ($text !== null) {
            Redis::rpush(self::CONFIRM_KEY . $identifier, $text);
        }

        return true;
    }

    /**
     * Prompt the user for confirmation via Redis and block until a reply arrives.
     *
     * @param  string  $message
     * @param  int  $timeout
     * @return bool
     */
    public function confirm(string $message, int $timeout = 120): bool
    {
        $identifier = $this->name . ':' . $this->conversationKey();
        $awaitingKey = self::AWAITING_KEY . $identifier;
        $confirmKey = self::CONFIRM_KEY . $identifier;

        // Signal to the handler that the next message is a confirmation reply
        Redis::set($awaitingKey, 1, 'EX', $timeout);

        // Clear any stale replies
        Redis::del($confirmKey);

        // Prompt the user
        $this->send("⚠️ {$message} Reply 'Yes' to confirm.");

        // This dedicated connection is declared in the service provider with
        // read_write_timeout = -1, overriding the default Redis socket timeout.
        $connection = Redis::connection('laraclaw-blocking');

        // blpop stands for "blocking left pop", blocks until a value is pushed
        // into the key or $timeout seconds pass, whichever comes first.
        $reply = $connection->blpop($confirmKey, $timeout);

        // Clean up the awaiting flag
        Redis::del($awaitingKey);

        return $reply && strtolower($reply[1]) === 'yes';
    }
}
