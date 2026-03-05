<?php

namespace LaraClaw\Commands;

use LaraClaw\DTOs\IncomingMessage;
use LaraClaw\Models\Thread;

/**
 * Command that resets the current user's conversation history.
 */
class NewConversation implements Command
{
    public function trigger(): string
    {
        return '!new';
    }

    public function handle(IncomingMessage $message, Thread $thread): ?string
    {
        $thread->update(['conversation_id' => null]);
        $thread->channel()->reply($thread, '✅ Conversation reset.');

        return null;
    }
}
