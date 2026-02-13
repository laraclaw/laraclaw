<?php

namespace App\Ai\Agents;

use App\Ai\Skills\SkillRegistry;
use App\Ai\Tools\UseSkill;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

#[MaxSteps(5)]
class ChatBot implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function instructions(): Stringable|string
    {
        return 'You are Laraclaw, a helpful AI assistant. Answer the user\'s question concisely and accurately.';
    }

    public function tools(): iterable
    {
        return [
            new UseSkill(app(SkillRegistry::class)),
        ];
    }

    protected function maxConversationMessages(): int
    {
        return 20;
    }
}
