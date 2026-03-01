<?php

namespace LaraClaw\Commands;

use Illuminate\Support\Facades\Cache;
use LaraClaw\Message;

/**
 * Command that resets the current user's conversation history.
 */
class NewConversation implements Command
{
    public function trigger(): string
    {
        return '!new';
    }

    public function handle(Message $message): ?Message
    {
        Cache::put("new_conversation:{$message->channel->name}:{$message->conversationKey}", true);
        $message->channel->send('Conversation reset. How can I help you?');

        return null;
    }
}
