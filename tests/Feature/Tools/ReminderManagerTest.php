<?php

use LaraClaw\Message;
use LaraClaw\Models\Reminder;
use LaraClaw\Tests\Fixtures\FakeChannel;
use LaraClaw\Tools\ReminderManager;
use Laravel\Ai\Tools\Request;

function reminderRequest(array $data): Request
{
    $mock = Mockery::mock(Request::class);
    $mock->allows('offsetGet')->andReturnUsing(fn ($key) => $data[$key] ?? null);
    $mock->allows('offsetExists')->andReturnUsing(fn ($key) => array_key_exists($key, $data));

    return $mock;
}

function reminderTool(): ReminderManager
{
    $channel = new FakeChannel;
    $message = new Message(
        channel: $channel,
        conversationKey: 'user-123',
        conversationIsDirectMessage: true,
        text: 'test',
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

// ── list ───────────────────────────────────────────────────────────────────

it('lists pending reminders as JSON', function () {
    Reminder::create([
        'user_id' => $this->user->id,
        'channel' => 'telegram',
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
        'channel' => 'telegram',
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
        'channel' => 'telegram',
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
