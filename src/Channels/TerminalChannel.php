<?php

namespace LaraClaw\Channels;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LaraClaw\Channels\Contracts\SupportsConfirmation;
use LaraClaw\DTOs\IncomingMessage;
use LaraClaw\Enums\ChannelType;
use LaraClaw\Models\Thread;

use function LaraClaw\Support\markdownToAnsi;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;

class TerminalChannel extends Channel implements SupportsConfirmation
{
    public ChannelType $type { get { return ChannelType::Terminal; } }

    /**
     * Build an IncomingMessage from a raw Slack event payload,
     * downloading any file attachments to storage.
     */
    public static function createIncomingMessageFrom(string $input, Authenticatable $user): IncomingMessage
    {
        $uuid = (string) Str::uuid();

        return new IncomingMessage(
            text: $input,
            channel: ChannelType::Terminal,
            key: $user->id,
            isDirectMessage: true,
            uuid: $uuid,
        );
    }

    /**
     * Terminal sessions are always direct messages.
     */
    public static function isDirectMessage(string $key): bool
    {
        return true;
    }

    /**
     * Render the response to the terminal with ANSI formatting.
     */
    public function reply(?Thread $thread, string $text, ?Collection $attachments = null)
    {
        return note(markdownToAnsi($text));
    }

    /**
     * Prompt for interactive confirmation via the terminal.
     */
    public function askForConfirmation(IncomingMessage $context, string $prompt, int $timeout = 120): bool
    {
        return confirm("⚠️ {$prompt}");
    }
}
