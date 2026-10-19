<?php

use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Cache;
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

function routineJob(string $key = 'user-123'): SendRoutine
{
    return new SendRoutine(Routine::create([
        'user_id' => test()->user->id,
        'connector' => 'telegram',
        'key' => $key,
        'prompt' => 'Good morning!',
        'cron' => '0 9 * * *',
        'is_active' => true,
    ]));
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

it('waits for the thread instead of running beside an inbound message', function () {
    $job = routineJob();
    $job->withFakeQueueInteractions();

    // Holding the lock around a closure hands it back even when something inside
    // blows up, so the next test does not inherit it.
    Cache::lock(Thread::lockKeyFor(ConnectorType::Telegram, 'user-123'), 60)->get(function () use ($job): void {
        app(Pipeline::class)
            ->send($job)
            ->through($job->middleware())
            ->then(fn () => throw new RuntimeException('The routine ran while a message held the lock.'));
    });

    $job->assertReleased(delay: 5);
});

it('is not dropped for waiting its turn', function () {
    $job = routineJob();

    // Every wait costs an attempt. A tries count would therefore fail a routine
    // that queued behind a turn lasting longer than two retry delays, so the job
    // leaves attempt counting alone and bounds itself by time instead.
    expect($job->tries ?? null)->toBeNull()
        ->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->getTimestamp());
});

it('still gives up on a run that keeps throwing', function () {
    // The single retry this job has always had, now expressed as exceptions so
    // that waiting for the lock cannot consume it.
    expect(routineJob()->maxExceptions)->toBe(2);
});
