<?php

namespace LaraClaw\Channels;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use LaraClaw\Channels\Contracts\SupportsConfirmation;
use LaraClaw\DTOs\Attachment;

class TerminalChannel extends Channel implements SupportsConfirmation
{
    public function __construct(private Command $command) {}

    /**
     * Conversation identifier keyed by process ID.
     */
    public function identifier(): string
    {
        return 'terminal:' . getmypid();
    }

    /**
     * Terminal has no concept of a remote user; always the owner.
     */
    public function userIdentifier(): ?string
    {
        return null;
    }

    public function intercept(?string $text): bool
    {
        return false;
    }

    public function shouldRespond(?string $text = null): bool
    {
        return true;
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
