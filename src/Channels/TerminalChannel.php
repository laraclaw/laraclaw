<?php

namespace LaraClaw\Channels;

use Illuminate\Support\Collection;
use LaraClaw\Channels\Contracts\SupportsConfirmation;
use LaraClaw\Message;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;

class TerminalChannel extends Channel implements SupportsConfirmation
{
    public string $name { get { return 'terminal'; } }

    private static ?string $pendingReply = null;

    /**
     * Use a fixed key so all terminal sessions share one account and conversation record.
     */
    public function conversationKey(): string
    {
        return 'default';
    }

    /**
     * Print each attachment filename to the terminal output.
     */
    public function handleAttachments(Collection $attachments): void
    {
        foreach ($attachments as $attachment) {
            info($attachment->filename ?? basename($attachment->path));
        }
    }

    /**
     * Buffer the response so the Chat command can render it after the spin completes.
     */
    public function send(string $message): void
    {
        self::$pendingReply = $message;
    }

    /**
     * Return the buffered reply and clear it so the next turn starts fresh.
     */
    public function flush(): ?string
    {
        $reply = self::$pendingReply;
        self::$pendingReply = null;

        return $reply;
    }

    /**
     * Prompt for interactive confirmation via the terminal.
     */
    public function confirm(Message $context, string $prompt, int $timeout = 120): bool
    {
        return confirm("⚠️ {$prompt}");
    }

    /**
     * Terminal never intercepts messages; always returns false.
     */
    public function intercept(Message $message): bool
    {
        return false;
    }
}
