<?php

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
        'message' => 'Good morning!',
        'cron' => '0 9 * * *',
        'is_active' => true,
        'last_run_at' => null,
    ]);

    $this->artisan(ProcessHeartbeats::class);

    Queue::assertPushed(SendHeartbeat::class, 1);
});

it('dispatches SendHeartbeat when the next scheduled time has passed', function () {
    Heartbeat::create([
        'user_id' => $this->user->id,
        'channel' => 'telegram',
        'key' => 'user-123',
        'message' => 'Weekly check-in',
        'cron' => '0 9 * * 1',  // Every Monday at 9am
        'is_active' => true,
        'last_run_at' => now()->subWeek()->subHour(),  // Last ran over a week ago
    ]);

    $this->artisan(ProcessHeartbeats::class);

    Queue::assertPushed(SendHeartbeat::class, 1);
});

it('does not dispatch when the next scheduled time has not yet passed', function () {
    Heartbeat::create([
        'user_id' => $this->user->id,
        'channel' => 'telegram',
        'key' => 'user-123',
        'message' => 'Daily digest',
        'cron' => '0 9 * * *',  // Every day at 9am
        'is_active' => true,
        'last_run_at' => now()->subMinutes(30),  // Ran 30 min ago — not due yet today
    ]);

    $this->artisan(ProcessHeartbeats::class);

    Queue::assertNotPushed(SendHeartbeat::class);
});

it('does not dispatch for inactive heartbeats', function () {
    Heartbeat::create([
        'user_id' => $this->user->id,
        'channel' => 'telegram',
        'key' => 'user-123',
        'message' => 'Disabled',
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
        'message' => 'Active',
        'cron' => '* * * * *',
        'is_active' => true,
        'last_run_at' => null,
    ]);

    Heartbeat::create([
        'user_id' => $this->user->id,
        'channel' => 'telegram',
        'key' => 'user-456',
        'message' => 'Inactive',
        'cron' => '* * * * *',
        'is_active' => false,
        'last_run_at' => null,
    ]);

    $this->artisan(ProcessHeartbeats::class);

    Queue::assertPushed(SendHeartbeat::class, 1);
});
