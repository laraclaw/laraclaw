<?php

namespace LaraClaw\Channels;

use Illuminate\Support\Collection;
use LaraClaw\Channels\Contracts\SupportsConfirmation;
use LaraClaw\DTOs\Attachment;
use LaraClaw\Message;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;

class TerminalChannel extends Channel implements SupportsConfirmation
{
    public string $name { get { return 'terminal'; } }

    private ?string $pendingResponse = null;

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
     * Buffer the response so it can be printed after the spinner clears.
     */
    public function send(string $message): void
    {
        $this->pendingResponse = $message;
    }

    /**
     * Return the buffered response and clear it.
     */
    public function flush(): ?string
    {
        $response = $this->pendingResponse;
        $this->pendingResponse = null;

        return $response;
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
