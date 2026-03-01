<?php

use LaraClaw\Message;
use LaraClaw\Models\Heartbeat;
use LaraClaw\Tests\Fixtures\FakeChannel;
use LaraClaw\Tools\HeartbeatManager;
use Laravel\Ai\Tools\Request;

function heartbeatRequest(array $data): Request
{
    $mock = Mockery::mock(Request::class);
    $mock->allows('offsetGet')->andReturnUsing(fn ($key) => $data[$key] ?? null);
    $mock->allows('offsetExists')->andReturnUsing(fn ($key) => array_key_exists($key, $data));

    return $mock;
}

function heartbeatTool(): HeartbeatManager
{
    $channel = new FakeChannel;
    $message = new Message(
        channel: $channel,
        conversationKey: 'user-123',
        conversationIsDirectMessage: true,
        text: 'test',
    );

    return new HeartbeatManager($message);
}

beforeEach(function () {
    $this->user = $this->createUser();
    config(['laraclaw.auth.admin_user_id' => $this->user->id]);
});

// ── create ─────────────────────────────────────────────────────────────────

it('creates a heartbeat with a valid cron expression', function () {
    $result = heartbeatTool()->handle(heartbeatRequest([
        'operation' => 'create',
        'message' => 'Good morning!',
        'cron' => '0 9 * * *',
    ]));

    expect(Heartbeat::count())->toBe(1);
    expect(Heartbeat::first()->cron)->toBe('0 9 * * *');
    expect(Heartbeat::first()->is_active)->toBeTrue();
    expect($result)->toContain('Good morning!');
});

it('rejects an invalid cron expression and does not persist', function () {
    $result = heartbeatTool()->handle(heartbeatRequest([
        'operation' => 'create',
        'message' => 'Bad schedule',
        'cron' => 'not-a-cron',
    ]));

    expect(Heartbeat::count())->toBe(0);
    expect($result)->toContain('Invalid cron expression');
});

it('returns an error when message is missing from create', function () {
    $result = heartbeatTool()->handle(heartbeatRequest([
        'operation' => 'create',
        'cron' => '0 9 * * *',
    ]));

    expect($result)->toContain('"message" parameter is required');
    expect(Heartbeat::count())->toBe(0);
});

it('returns an error when cron is missing from create', function () {
    $result = heartbeatTool()->handle(heartbeatRequest([
        'operation' => 'create',
        'message' => 'Missing cron',
    ]));

    expect($result)->toContain('"cron" parameter is required');
    expect(Heartbeat::count())->toBe(0);
});

// ── list ───────────────────────────────────────────────────────────────────

it('lists active heartbeats as JSON', function () {
    Heartbeat::create([
        'user_id' => $this->user->id,
        'channel' => 'telegram',
        'key' => 'user-123',
        'message' => 'Daily standup',
        'cron' => '0 9 * * 1-5',
        'is_active' => true,
    ]);

    $result = heartbeatTool()->handle(heartbeatRequest(['operation' => 'list']));

    expect($result)->toContain('Daily standup');
});

it('returns a no-heartbeats message when none are active', function () {
    $result = heartbeatTool()->handle(heartbeatRequest(['operation' => 'list']));

    expect($result)->toBe('No active heartbeats.');
});

it('does not include cancelled heartbeats in the list', function () {
    Heartbeat::create([
        'user_id' => $this->user->id,
        'channel' => 'telegram',
        'key' => 'user-123',
        'message' => 'Cancelled',
        'cron' => '0 9 * * *',
        'is_active' => false,
    ]);

    $result = heartbeatTool()->handle(heartbeatRequest(['operation' => 'list']));

    expect($result)->toBe('No active heartbeats.');
});

// ── cancel ─────────────────────────────────────────────────────────────────

it('cancels a heartbeat by setting is_active to false', function () {
    $heartbeat = Heartbeat::create([
        'user_id' => $this->user->id,
        'channel' => 'telegram',
        'key' => 'user-123',
        'message' => 'Cancel me',
        'cron' => '0 9 * * *',
        'is_active' => true,
    ]);

    $result = heartbeatTool()->handle(heartbeatRequest([
        'operation' => 'cancel',
        'id' => (string) $heartbeat->id,
    ]));

    expect(Heartbeat::first()->is_active)->toBeFalse();
    expect($result)->toContain('cancelled');
});

it('returns not-found when cancelling a non-existent heartbeat', function () {
    $result = heartbeatTool()->handle(heartbeatRequest([
        'operation' => 'cancel',
        'id' => '9999',
    ]));

    expect($result)->toContain('not found');
});

it('returns an error when cancel is called without an id', function () {
    $result = heartbeatTool()->handle(heartbeatRequest(['operation' => 'cancel']));

    expect($result)->toContain('"id" parameter is required');
});
