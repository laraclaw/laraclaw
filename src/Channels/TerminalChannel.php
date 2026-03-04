<?php

namespace LaraClaw\Channels;

use Illuminate\Support\Collection;
use LaraClaw\Channels\Contracts\SupportsConfirmation;
use LaraClaw\Message;

use function LaraClaw\Support\markdownToAnsi;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;

class TerminalChannel extends Channel implements SupportsConfirmation
{
    public string $name { get { return 'terminal'; } }

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
     * Render the response to the terminal with ANSI formatting.
     */
    public function send(string $message): void
    {
        note(markdownToAnsi($message));
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
