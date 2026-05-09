<?php

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
