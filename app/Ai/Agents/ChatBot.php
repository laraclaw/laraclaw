<?php

namespace App\Ai\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Promptable;
use Stringable;

class ChatBot implements Agent, Conversational
{
    use Promptable, RemembersConversations;

    public function instructions(): Stringable|string
    {
        return 'You are Laraclaw, a helpful AI assistant. Answer the user\'s question concisely and accurately.';
    }

    protected function maxConversationMessages(): int
    {
        return 20;
    }
}
