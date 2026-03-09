<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use LaraClaw\Console\Commands\ProcessHeartbeats;
use LaraClaw\Jobs\SendHeartbeat;
use LaraClaw\Models\Heartbeat;

beforeEach(function () {
    Queue::fake();
    $this->user = $this->createUser();
    config(['laraclaw.auth.admin_user_id' => $this->user->id]);
});

it('dispatches SendHeartbeat immediately when last_run_at is null', function () {
    Heartbeat::create([
        'user_id' => $this->user->id,
        'channel' => 'telegram',
        'key' => 'user-123',
        'prompt' => 'Good morning!',
        'cron' => '0 9 * * *',
        'is_active' => true,
        'last_run_at' => null,
    ]);

    $this->artisan(ProcessHeartbeats::class);

    Queue::assertPushed(SendHeartbeat::class, 1);
});

it('dispatches SendHeartbeat when the next scheduled time has passed', function () {
    // Freeze to Monday 2024-01-08 at 10:00 — next run after last Monday's 9am has elapsed
    $this->travelTo(Carbon::create(2024, 1, 8, 10, 0, 0));

    Heartbeat::create([
        'user_id' => $this->user->id,
        'channel' => 'telegram',
        'key' => 'user-123',
        'prompt' => 'Weekly check-in',
        'cron' => '0 9 * * 1',  // Every Monday at 9am
        'is_active' => true,
        'last_run_at' => Carbon::create(2024, 1, 1, 9, 0, 0),  // Previous Monday at 9am
    ]);

    $this->artisan(ProcessHeartbeats::class);

    Queue::assertPushed(SendHeartbeat::class, 1);
});

it('does not dispatch when the next scheduled time has not yet passed', function () {
    // Freeze to 2024-01-08 at 08:00 — next 9am run is still one hour away
    $this->travelTo(Carbon::create(2024, 1, 8, 8, 0, 0));

    Heartbeat::create([
        'user_id' => $this->user->id,
        'channel' => 'telegram',
        'key' => 'user-123',
        'prompt' => 'Daily digest',
        'cron' => '0 9 * * *',  // Every day at 9am
        'is_active' => true,
        'last_run_at' => Carbon::create(2024, 1, 7, 9, 0, 0),  // Yesterday at 9am; next run is today at 9am
    ]);

    $this->artisan(ProcessHeartbeats::class);

    Queue::assertNotPushed(SendHeartbeat::class);
});

it('does not dispatch for inactive heartbeats', function () {
    Heartbeat::create([
        'user_id' => $this->user->id,
        'channel' => 'telegram',
        'key' => 'user-123',
        'prompt' => 'Disabled',
        'cron' => '* * * * *',
        'is_active' => false,
        'last_run_at' => null,
    ]);

    $this->artisan(ProcessHeartbeats::class);

    Queue::assertNotPushed(SendHeartbeat::class);
});

it('only dispatches active heartbeats when mixed with inactive ones', function () {
    Heartbeat::create([
        'user_id' => $this->user->id,
        'channel' => 'telegram',
        'key' => 'user-123',
        'prompt' => 'Active',
        'cron' => '* * * * *',
        'is_active' => true,
        'last_run_at' => null,
    ]);

    Heartbeat::create([
        'user_id' => $this->user->id,
        'channel' => 'telegram',
        'key' => 'user-456',
        'prompt' => 'Inactive',
        'cron' => '* * * * *',
        'is_active' => false,
        'last_run_at' => null,
    ]);

    $this->artisan(ProcessHeartbeats::class);

    Queue::assertPushed(SendHeartbeat::class, 1);
});
