<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Laraclaw\Console\Commands\ProcessRoutines;
use Laraclaw\Jobs\SendRoutine;
use Laraclaw\Models\Routine;

beforeEach(function () {
    Queue::fake();
    $this->user = $this->createUser();
    config(['laraclaw.auth.admin_user_id' => $this->user->id]);
});

it('dispatches SendRoutine immediately when last_run_at is null', function () {
    Routine::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'prompt' => 'Good morning!',
        'cron' => '0 9 * * *',
        'is_active' => true,
        'last_run_at' => null,
    ]);

    $this->artisan(ProcessRoutines::class);

    Queue::assertPushed(SendRoutine::class, 1);
});

it('dispatches SendRoutine when the next scheduled time has passed', function () {
    // Freeze to Monday 2024-01-08 at 10:00 — next run after last Monday's 9am has elapsed
    $this->travelTo(Carbon::create(2024, 1, 8, 10, 0, 0));

    Routine::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'prompt' => 'Weekly check-in',
        'cron' => '0 9 * * 1',  // Every Monday at 9am
        'is_active' => true,
        'last_run_at' => Carbon::create(2024, 1, 1, 9, 0, 0),  // Previous Monday at 9am
    ]);

    $this->artisan(ProcessRoutines::class);

    Queue::assertPushed(SendRoutine::class, 1);
});

it('does not dispatch when the next scheduled time has not yet passed', function () {
    // Freeze to 2024-01-08 at 08:00 — next 9am run is still one hour away
    $this->travelTo(Carbon::create(2024, 1, 8, 8, 0, 0));

    Routine::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'prompt' => 'Daily digest',
        'cron' => '0 9 * * *',  // Every day at 9am
        'is_active' => true,
        'last_run_at' => Carbon::create(2024, 1, 7, 9, 0, 0),  // Yesterday at 9am; next run is today at 9am
    ]);

    $this->artisan(ProcessRoutines::class);

    Queue::assertNotPushed(SendRoutine::class);
});

it('does not dispatch for inactive routines', function () {
    Routine::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'prompt' => 'Disabled',
        'cron' => '* * * * *',
        'is_active' => false,
        'last_run_at' => null,
    ]);

    $this->artisan(ProcessRoutines::class);

    Queue::assertNotPushed(SendRoutine::class);
});

it('only dispatches active routines when mixed with inactive ones', function () {
    Routine::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'prompt' => 'Active',
        'cron' => '* * * * *',
        'is_active' => true,
        'last_run_at' => null,
    ]);

    Routine::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-456',
        'prompt' => 'Inactive',
        'cron' => '* * * * *',
        'is_active' => false,
        'last_run_at' => null,
    ]);

    $this->artisan(ProcessRoutines::class);

    Queue::assertPushed(SendRoutine::class, 1);
});

it('does not dispatch the same routine twice when a second pass overlaps the first', function () {
    $routine = Routine::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'prompt' => 'Only once',
        'cron' => '* * * * *',
        'is_active' => true,
        'last_run_at' => null,
    ]);

    $this->artisan(ProcessRoutines::class);

    // Drop the unique job lock so the second pass is stopped by the database
    // claim alone, which is the guarantee we actually care about here.
    releaseUniqueLock(new SendRoutine($routine->fresh(), null));

    $this->artisan(ProcessRoutines::class);

    Queue::assertPushed(SendRoutine::class, 1);
    expect($routine->fresh()->last_run_at)->not->toBeNull();
});

it('dispatches the routine again once a failed job releases its claim', function () {
    $routine = Routine::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'prompt' => 'Retry me',
        'cron' => '* * * * *',
        'is_active' => true,
        'last_run_at' => null,
    ]);

    $this->artisan(ProcessRoutines::class);

    (new SendRoutine($routine->fresh(), null))->failed(new RuntimeException('agent exploded'));
    releaseUniqueLock(new SendRoutine($routine->fresh(), null));

    expect($routine->fresh()->last_run_at)->toBeNull();

    $this->artisan(ProcessRoutines::class);

    Queue::assertPushed(SendRoutine::class, 2);
});

it('restores the previous run time when a routine job fails', function () {
    $previousRunAt = Carbon::create(2024, 1, 1, 9, 0, 0);

    $routine = Routine::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'prompt' => 'Weekly check-in',
        'cron' => '0 9 * * 1',
        'is_active' => true,
        'last_run_at' => $previousRunAt,
    ]);

    (new SendRoutine($routine, $previousRunAt))->failed(new RuntimeException('agent exploded'));

    expect($routine->fresh()->last_run_at->equalTo($previousRunAt))->toBeTrue();
});
