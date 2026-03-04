<?php

namespace LaraClaw\Channels;

use Illuminate\Support\Collection;
use LaraClaw\Channels\Contracts\SupportsConfirmation;
use LaraClaw\Message;

use function LaraClaw\Support\markdownToAnsi;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;

class TerminalChannel extends Channel implements SupportsConfirmation
{
    public string $name { get { return 'terminal'; } }

    /**
     * Use the current process ID as the conversation key.
     */
    public function conversationKey(): string
    {
        return (string) getmypid();
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
     * Render and print the response to the terminal.
     */
    public function send(string $message): void
    {
        info(markdownToAnsi($message));
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
