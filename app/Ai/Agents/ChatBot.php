<?php

namespace App\Ai\Agents;

use App\Ai\Skills\SkillRegistry;
use App\Ai\Tools\DiskManager;
use App\Ai\Tools\ImageManager;
use App\Ai\Tools\UseSkill;
use App\Ai\Tools\WebRequest;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;
use Stringable;

#[MaxSteps(5)]
class ChatBot implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function instructions(): Stringable|string
    {
        $base = 'You are Laraclaw, a helpful AI assistant. Answer the user\'s question concisely and accurately.';
        $persona = config('laraclaw.persona');

        if ($persona && Storage::disk('laraclaw')->exists("personas/{$persona}.md")) {
            return Storage::disk('laraclaw')->get("personas/{$persona}.md") . "\n\n" . $base;
        }

        return $base;
    }

    public function tools(): iterable
    {
        return [
            new UseSkill(app(SkillRegistry::class)),
            new DiskManager(),
            new ImageManager(),
            new WebRequest(),
            new WebSearch(),
        ];
    }

    protected function maxConversationMessages(): int
    {
        return 20;
    }
}
