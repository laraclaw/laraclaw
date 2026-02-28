<?php

namespace LaraClaw\Channels;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use LaraClaw\Channels\Contracts\SupportsConfirmation;
use LaraClaw\DTOs\Attachment;

class TerminalChannel extends Channel implements SupportsConfirmation
{
    /**
     * Create a new TerminalChannel instance.
     *
     * @param  \Illuminate\Console\Command  $command
     */
    public function __construct(private Command $command) {}

    public readonly string $name = 'terminal';

    public function conversationKey(): string
    {
        return (string) getmypid();
    }

    public function conversationIsDirectMessage(): bool
    {
        return false;
    }

    /**
     * Terminal never intercepts messages; always returns false.
     *
     * @param  string|null  $text
     * @return bool
     */
    public function intercept(?string $text): bool
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
     *
     * @param  string  $message
     * @return void
     */
    public function send(string $message): void
    {
        $this->command->info($message);
    }

    /**
     * Prompt for interactive confirmation via the Artisan command.
     */
    public function confirm(string $message, int $timeout = 120): bool
    {
        return $this->command->confirm("⚠️ {$message}");
    }
}
