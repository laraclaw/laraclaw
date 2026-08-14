<?php

use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Reminder;
use Laraclaw\Tools\ReminderManager;
use Laravel\Ai\Tools\Request;

function reminderRequest(array $data): Request
{
    return new Request($data, 'call_test');
}

function reminderTool(): ReminderManager
{
    $message = new IncomingMessage(
        text: 'test',
        connector: ConnectorType::Telegram,
        key: 'user-123',
        isDirectMessage: true,
    );

    return new ReminderManager($message);
}

beforeEach(function () {
    $this->user = $this->createUser();
    config(['laraclaw.auth.admin_user_id' => $this->user->id]);
});

// ── create ─────────────────────────────────────────────────────────────────

it('creates a reminder and persists it to the database', function () {
    $tool = reminderTool();

    $result = $tool->handle(reminderRequest([
        'operation' => 'create',
        'message' => 'Take your meds',
        'remind_at' => now()->addHour()->toIso8601String(),
    ]));

    expect(Reminder::count())->toBe(1);
    expect(Reminder::first()->message)->toBe('Take your meds');
    expect($result)->toContain('Take your meds');
});

it('returns an error when message is missing from create', function () {
    $result = reminderTool()->handle(reminderRequest([
        'operation' => 'create',
        'remind_at' => now()->addHour()->toIso8601String(),
    ]));

    expect($result)->toContain('"message" parameter is required');
    expect(Reminder::count())->toBe(0);
});

it('returns an error when remind_at is missing from create', function () {
    $result = reminderTool()->handle(reminderRequest([
        'operation' => 'create',
        'message' => 'Forgot something',
    ]));

    expect($result)->toContain('"remind_at" parameter is required');
    expect(Reminder::count())->toBe(0);
});

it('refuses to create a reminder for an API thread', function () {
    $message = new IncomingMessage(
        text: 'test',
        connector: ConnectorType::Api,
        key: 'token-abc',
        isDirectMessage: true,
    );
    $tool = new ReminderManager($message);

    $result = $tool->handle(reminderRequest([
        'operation' => 'create',
        'message' => 'Take your meds',
        'remind_at' => now()->addHour()->toIso8601String(),
    ]));

    expect($result)->toContain('API threads cannot receive scheduled reminders');
    expect(Reminder::count())->toBe(0);
});

// ── list ───────────────────────────────────────────────────────────────────

it('lists pending reminders as JSON', function () {
    Reminder::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'message' => 'Pending reminder',
        'remind_at' => now()->addHour(),
    ]);

    $result = reminderTool()->handle(reminderRequest(['operation' => 'list']));

    expect($result)->toContain('Pending reminder');
});

it('returns a no-reminders message when the list is empty', function () {
    $result = reminderTool()->handle(reminderRequest(['operation' => 'list']));

    expect($result)->toBe('No pending reminders.');
});

it('does not include already-sent reminders in the list', function () {
    Reminder::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'message' => 'Already sent',
        'remind_at' => now()->subMinute(),
        'sent_at' => now(),
    ]);

    $result = reminderTool()->handle(reminderRequest(['operation' => 'list']));

    expect($result)->toBe('No pending reminders.');
});

// ── cancel ─────────────────────────────────────────────────────────────────

it('cancels a pending reminder by ID', function () {
    $reminder = Reminder::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'message' => 'Cancel me',
        'remind_at' => now()->addHour(),
    ]);

    $result = reminderTool()->handle(reminderRequest([
        'operation' => 'cancel',
        'id' => (string) $reminder->id,
    ]));

    expect(Reminder::count())->toBe(0);
    expect($result)->toContain('cancelled');
});

it('returns not-found when cancelling a non-existent reminder', function () {
    $result = reminderTool()->handle(reminderRequest([
        'operation' => 'cancel',
        'id' => '9999',
    ]));

    expect($result)->toContain('not found');
});

it('returns an error when cancel is called without an id', function () {
    $result = reminderTool()->handle(reminderRequest(['operation' => 'cancel']));

    expect($result)->toContain('"id" parameter is required');
});

it('creates a reminder from a relative phrase the model wrote', function () {
    // "in 2 minutes" used to fail outright and the user got an error instead of
    // a reminder.
    $this->travelTo('2026-08-13 12:00:00', function () {
        $result = reminderTool()->handle(reminderRequest([
            'operation' => 'create',
            'message' => 'Check the oven',
            'remind_at' => 'in 2 minutes',
        ]));

        expect($result)->toContain('2026-08-13 12:02:00');
        expect(Reminder::first()->remind_at->toDateTimeString())->toBe('2026-08-13 12:02:00');
    });
});

it('schedules a reminder in the application timezone', function () {
    withTimezone('America/Argentina/Buenos_Aires');

    // Noon UTC is nine in the morning in Buenos Aires, so "in 2 minutes" lands
    // on 09:02 local rather than the 12:02 a UTC app would have written.
    $this->travelTo('2026-08-13 12:00:00 UTC', function () {
        $result = reminderTool()->handle(reminderRequest([
            'operation' => 'create',
            'message' => 'Check the oven',
            'remind_at' => 'in 2 minutes',
        ]));

        expect($result)->toContain('2026-08-13 09:02:00 (America/Argentina/Buenos_Aires)');
        expect(Reminder::first()->remind_at->toDateTimeString())->toBe('2026-08-13 09:02:00');
    });
});

it('reports pending reminders in the application timezone', function () {
    withTimezone('America/Argentina/Buenos_Aires');

    Reminder::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'message' => 'Pending reminder',
        'remind_at' => '2026-08-13 09:02:00',
    ]);

    $result = reminderTool()->handle(reminderRequest(['operation' => 'list']));

    expect($result)->toContain('2026-08-13T09:02:00-03:00');
});
