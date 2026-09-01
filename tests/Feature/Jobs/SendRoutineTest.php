<?php

use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Cache;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Jobs\SendRoutine;
use Laraclaw\Models\Routine;
use Laraclaw\Models\Thread;

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

beforeEach(function () {
    $this->user = $this->createUser();
    config(['laraclaw.auth.admin_user_id' => $this->user->id]);
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
