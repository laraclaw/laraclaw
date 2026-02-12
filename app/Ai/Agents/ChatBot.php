<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider('anthropic')]
class ChatBot implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are Laraclaw, a helpful AI assistant. Answer the user\'s question concisely and accurately.';
    }
}
