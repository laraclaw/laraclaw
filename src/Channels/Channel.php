<?php

namespace LaraClaw\Channels;

use LaraClaw\Channels\DTOs\Attachment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;

/**
 * Base class for all bot communication channels (Telegram, Slack, Email, CLI, etc.)
 *
 * Subclasses only need to implement identifier() and send().
 */
abstract class Channel
{
    protected ?string $messageText = null;

    /** @var Collection<int, Attachment> */
    protected Collection $messageAttachments;

    abstract public function identifier(): string;
    abstract public function send(string $message): void;

    public function text(): ?string
    {
        return $this->messageText;
    }

    /** @return Collection<int, Attachment> */
    public function attachments(): Collection
    {
        return $this->messageAttachments ??= collect();
    }

    public function acknowledge(): void
    {
        // No-op by default. Subclasses can override to send typing indicators, reactions, etc.
    }

    public function sendAudio(string $filePath): void
    {
        // No-op by default. Subclasses that support audio should override this.
    }

    public function sendPhoto(string $disk, string $path): void
    {
        // No-op by default. Subclasses that support photo sending should override this.
    }

    /**
     * Ask the user to confirm a dangerous action.
     *
     * Blocks the current process until the user replies or the timeout expires.
     * The matching handler must push the reply to the same Redis key:
     *
     *   Redis::rpush("confirm:telegram:123456", "yes");
     *
     * Overriding is optional for channels that don't use Redis
     * (e.g. TerminalChannel uses Artisan's native CLI prompt instead).
     *
     * @param  string  $message  Human-readable description of what's about to happen.
     * @param  int     $timeout  Max seconds to wait before treating as rejection.
     * @return bool              True if the user confirmed, false otherwise.
     */
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

        // Block until the handler pushes a reply or we time out.
        // Use the laraclaw-blocking connection (read_write_timeout = -1) so Predis
        // doesn't throw a TimeoutException before blpop returns naturally.
        $reply = Redis::connection('laraclaw-blocking')->blpop($confirmKey, $timeout);

        // Clean up the awaiting flag
        Redis::del($awaitingKey);

        return $reply && strtolower($reply[1]) === 'yes';
    }
}
