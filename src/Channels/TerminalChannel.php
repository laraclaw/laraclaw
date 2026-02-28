<?php

namespace LaraClaw\Channels;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use LaraClaw\Channels\Contracts\SupportsConfirmation;
use LaraClaw\DTOs\Attachment;
use LaraClaw\Message;

class TerminalChannel extends Channel implements SupportsConfirmation
{
    public readonly string $name = 'terminal';

    /**
     * Create a new TerminalChannel instance.
     */
    public function __construct(private Command $command) {}

    /**
     * Use the current process ID as the conversation key.
     */
    public function conversationKey(): string
    {
        return (string) getmypid();
    }

    /**
     * Terminal sessions are always direct messages.
     */
    public function conversationIsDirectMessage(): bool
    {
        return true;
    }

    /**
     * Terminal never intercepts messages; always returns false.
     */
    public function intercept(Message $message): bool
    {
        return false;
    }

    /**
     * Print each attachment filename to the terminal output.
     */
    public function handleAttachments(Collection $attachments): void
    {
        foreach ($attachments as $attachment) {
            $this->command->line($attachment->filename ?? basename($attachment->path));
        }
    }

    /**
     * Output the message to the terminal.
     */
    public function send(string $message): void
    {
        $this->command->info($message);
    }

    /**
     * Prompt for interactive confirmation via the Artisan command.
     */
    public function confirm(Message $context, string $prompt, int $timeout = 120): bool
    {
        return $this->command->confirm("⚠️ {$prompt}");
    }
}
