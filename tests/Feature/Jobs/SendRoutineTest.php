<?php

use Laraclaw\Agents\ChatBotAgent;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Jobs\SendRoutine;
use Laraclaw\Models\Account;
use Laraclaw\Models\Routine;
use Laraclaw\Models\Thread;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Prompts\Prompt;

beforeEach(function () {
    $this->user = $this->createUser();
    config(['laraclaw.auth.admin_user_id' => $this->user->id]);

    Account::create([
        'connector' => ConnectorType::Terminal,
        'account' => 'user-1',
        'user_id' => $this->user->id,
    ]);

    // The terminal connector prints the reply, which is noise here.
    Prompt::fake();
});

function dishesRoutine(): Routine
{
    return Routine::create([
        'user_id' => test()->user->id,
        'connector' => ConnectorType::Terminal,
        'key' => 'user-1',
        'prompt' => 'Remind me to do the dishes',
        'cron' => '0 7 * * 1-5',
        'is_active' => true,
    ]);
}

it('tells the agent a routine is firing rather than replaying the request to schedule it', function () {
    // The prompt is usually stored in the user's own words and lands right after
    // the agent agreed to schedule it, so sent bare it reads as the same request
    // again and the reply is "sure, every weekday at 7am" instead of the reminder.
    ChatBotAgent::fake(['Go do the dishes.']);

    (new SendRoutine(dishesRoutine()))->handle();

    ChatBotAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'scheduled routine going off')
        && str_contains($prompt->prompt, 'not a new message from the user')
        && str_ends_with($prompt->prompt, 'Remind me to do the dishes'));
});

it('keeps the conversation and stamps the run once the reply is delivered', function () {
    ChatBotAgent::fake(['Go do the dishes.']);

    $routine = dishesRoutine();

    (new SendRoutine($routine))->handle();

    expect($routine->fresh()->last_run_at)->not->toBeNull()
        ->and(Thread::where('key', 'user-1')->value('conversation_id'))->not->toBeNull();
});
