<?php

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;
use Laraclaw\Console\Commands\SendReminders;
use Laraclaw\Jobs\SendReminder;
use Laraclaw\Models\Reminder;

beforeEach(function () {
    Queue::fake();
    $this->user = $this->createUser();
    config(['laraclaw.auth.admin_user_id' => $this->user->id]);
});

it('dispatches SendReminder for overdue unsent reminders', function () {
    Reminder::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'message' => 'Time to go!',
        'remind_at' => now()->subMinute(),
    ]);

    $this->artisan(SendReminders::class);

    Queue::assertPushed(SendReminder::class, 1);
});

it('does not dispatch for reminders that already have sent_at', function () {
    Reminder::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'message' => 'Already done',
        'remind_at' => now()->subMinute(),
        'sent_at' => now()->subSecond(),
    ]);

    $this->artisan(SendReminders::class);

    Queue::assertNotPushed(SendReminder::class);
});

it('does not dispatch for reminders scheduled in the future', function () {
    Reminder::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'message' => 'Not yet',
        'remind_at' => now()->addHour(),
    ]);

    $this->artisan(SendReminders::class);

    Queue::assertNotPushed(SendReminder::class);
});

it('dispatches multiple reminders in one run', function () {
    Reminder::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'message' => 'First',
        'remind_at' => now()->subMinutes(2),
    ]);

    Reminder::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-456',
        'message' => 'Second',
        'remind_at' => now()->subMinute(),
    ]);

    $this->artisan(SendReminders::class);

    Queue::assertPushed(SendReminder::class, 2);
});

it('does not dispatch the same reminder twice when a second pass overlaps the first', function () {
    $reminder = Reminder::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'message' => 'Only once',
        'remind_at' => now()->subMinute(),
    ]);

    $this->artisan(SendReminders::class);

    // Drop the unique job lock so the second pass is stopped by the database
    // claim alone, which is the guarantee we actually care about here.
    releaseUniqueLock(new SendReminder($reminder));

    $this->artisan(SendReminders::class);

    Queue::assertPushed(SendReminder::class, 1);
    expect($reminder->fresh()->sent_at)->not->toBeNull();
});

it('dispatches the reminder again once a failed job releases its claim', function () {
    $reminder = Reminder::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'message' => 'Retry me',
        'remind_at' => now()->subMinute(),
    ]);

    $this->artisan(SendReminders::class);

    (new SendReminder($reminder))->failed(new RuntimeException('connector exploded'));
    releaseUniqueLock(new SendReminder($reminder));

    $this->artisan(SendReminders::class);

    Queue::assertPushed(SendReminder::class, 2);
});

it('claims every due reminder even when they span more than one chunk', function () {
    // The claim writes sent_at, which is part of this query's own filter, so
    // claimed rows drop out of the result set while the walk is still going.
    // One row past the 1000 row chunk size is enough to catch an offset walk
    // stepping over the reminders that shuffled down behind it.
    $rows = collect(range(1, 1001))
        ->map(fn (int $index) => [
            'user_id' => $this->user->id,
            'connector' => 'telegram',
            'key' => 'user-' . $index,
            'message' => 'Reminder ' . $index,
            'remind_at' => now()->subMinute(),
        ])
        ->all();

    Reminder::insert($rows);

    $this->artisan(SendReminders::class);

    Queue::assertPushed(SendReminder::class, 1001);
    expect(Reminder::whereNull('sent_at')->count())->toBe(0);
});

it('releases the claim when the job cannot be queued', function () {
    $reminder = Reminder::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'message' => 'Queue is down',
        'remind_at' => now()->subMinute(),
    ]);

    $this->mock(Dispatcher::class)
        ->shouldReceive('dispatch')
        ->andThrow(new RuntimeException('queue is unreachable'));

    expect(fn () => $this->artisan(SendReminders::class))
        ->toThrow(RuntimeException::class);

    expect($reminder->fresh()->sent_at)->toBeNull();
});
