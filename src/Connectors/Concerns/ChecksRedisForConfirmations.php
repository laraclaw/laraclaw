<?php

namespace LaraClaw\Connectors\Concerns;

use Illuminate\Support\Facades\Redis;
use LaraClaw\DTOs\IncomingMessage;
use LaraClaw\Models\Thread;

trait ChecksRedisForConfirmations
{
    private const AWAITING_KEY = 'awaiting_confirm:';

    private const CONFIRM_KEY = 'confirm:';

    /**
     * Intercept an incoming message if a confirmation is pending for this connector.
     *
     * Returns true if the message was consumed and should not be processed further.
     */
    public function resolvePendingConfirmation(IncomingMessage $message): bool
    {
        $key = $this->type->value . ':' . $message->key;

        // If no confirmation is pending for this conversation, the incoming message
        // is not a reply to a pending confirm prompt and we should not intercept it.
        // Returning false signals that the message can proceed as normal.
        if (! Redis::exists(self::AWAITING_KEY . $key)) {
            return false;
        }

        // A confirmation is pending, so push the message text into the queue for
        // the confirm blpop call to pick up. If the text is null (a file with no caption)
        // we skip the push but still return true to suppress normal handling.
        if ($message->text !== null) {
            Redis::rpush(self::CONFIRM_KEY . $key, $message->text);
        }

        return true;
    }

    /**
     * Prompt the user for confirmation via Redis and block until a reply arrives.
     */
    public function askForConfirmation(IncomingMessage $message, string $prompt, int $timeout = 120): bool
    {
        $key = $this->type->value . ':' . $message->key;
        $awaitingKey = self::AWAITING_KEY . $key;
        $confirmKey = self::CONFIRM_KEY . $key;

        // Signal to the handler that the next message is a confirmation reply
        Redis::set($awaitingKey, 1, 'EX');

        // Clear any stale replies
        Redis::del($confirmKey);

        // Prompt the user via the thread's outbound connector.
        $thread = Thread::forMessage($message);
        $thread->connector()->reply($thread, "⚠️ {$prompt} Reply 'Yes' to confirm.");

        // This dedicated connection is declared in the service provider with
        // read_write_timeout = -1, overriding the default Redis socket timeout.
        $connection = Redis::connection('laraclaw-blocking');

        try {
            // blpop stands for "blocking left pop", blocks until a value is pushed
            // into the key or $timeout seconds pass, whichever comes first.
            $reply = $connection->blpop($confirmKey, $timeout);

            // Extract the first non-empty line so email replies with quoted
            // content below ("On Mar 6, Bot wrote: …") still match.
            $firstLine = trim(strtok(trim((string) $reply[1]), "\n"));

            return $reply && strtolower($firstLine) === 'yes';
        } finally {
            // Always clean up both keys so they never leak, even if the job is
            // killed, the connection drops, or an exception is thrown while we wait.
            Redis::del($awaitingKey, $confirmKey);
        }
    }
}
