<?php

namespace Laraclaw\Commands;

use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Models\Thread;

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
        $thread->connector()->reply($thread, '✅ Conversation reset.');

        return null;
    }
}
