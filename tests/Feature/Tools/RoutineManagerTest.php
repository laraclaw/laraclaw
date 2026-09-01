<?php

use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Routine;
use Laraclaw\Tools\RoutineManager;
use Laravel\Ai\Tools\Request;

function routineRequest(array $data): Request
{
    return new Request($data, 'call_test');
}

function routineTool(): RoutineManager
{
    $message = new IncomingMessage(
        text: 'test',
        connector: ConnectorType::Telegram,
        key: 'user-123',
        isDirectMessage: true,
    );

    return new RoutineManager($message);
}

beforeEach(function () {
    $this->user = $this->createUser();
    config(['laraclaw.auth.admin_user_id' => $this->user->id]);
});

// ── create ─────────────────────────────────────────────────────────────────

it('creates a routine with a valid cron expression', function () {
    $result = routineTool()->handle(routineRequest([
        'operation' => 'create',
        'prompt' => 'Give me a morning briefing',
        'cron' => '0 9 * * *',
    ]));

    expect(Routine::count())->toBe(1);
    expect(Routine::first()->cron)->toBe('0 9 * * *');
    expect(Routine::first()->is_active)->toBeTrue();
    expect($result)->toContain('Give me a morning briefing');
});

it('rejects an invalid cron expression and does not persist', function () {
    $result = routineTool()->handle(routineRequest([
        'operation' => 'create',
        'prompt' => 'Bad schedule',
        'cron' => 'not-a-cron',
    ]));

    expect(Routine::count())->toBe(0);
    expect($result)->toContain('Invalid cron expression');
});

it('returns an error when prompt is missing from create', function () {
    $result = routineTool()->handle(routineRequest([
        'operation' => 'create',
        'cron' => '0 9 * * *',
    ]));

    expect($result)->toContain('"prompt" parameter is required');
    expect(Routine::count())->toBe(0);
});

it('returns an error when cron is missing from create', function () {
    $result = routineTool()->handle(routineRequest([
        'operation' => 'create',
        'prompt' => 'Missing cron',
    ]));

    expect($result)->toContain('"cron" parameter is required');
    expect(Routine::count())->toBe(0);
});

it('refuses to create a routine for an API thread', function () {
    $message = new IncomingMessage(
        text: 'test',
        connector: ConnectorType::Api,
        key: 'token-abc',
        isDirectMessage: true,
    );
    $tool = new RoutineManager($message);

    $result = $tool->handle(routineRequest([
        'operation' => 'create',
        'prompt' => 'Daily briefing',
        'cron' => '0 9 * * *',
    ]));

    expect($result)->toContain('API threads cannot receive routines');
    expect(Routine::count())->toBe(0);
});

// ── list ───────────────────────────────────────────────────────────────────

it('lists active routines as JSON', function () {
    Routine::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'prompt' => 'Give me today\'s standup summary',
        'cron' => '0 9 * * 1-5',
        'is_active' => true,
    ]);

    $result = routineTool()->handle(routineRequest(['operation' => 'list']));

    expect($result)->toContain('standup summary');
});

it('returns a no-routines message when none are active', function () {
    $result = routineTool()->handle(routineRequest(['operation' => 'list']));

    expect($result)->toBe('No active routines.');
});

it('does not include cancelled routines in the list', function () {
    Routine::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'prompt' => 'Cancelled',
        'cron' => '0 9 * * *',
        'is_active' => false,
    ]);

    $result = routineTool()->handle(routineRequest(['operation' => 'list']));

    expect($result)->toBe('No active routines.');
});

// ── cancel ─────────────────────────────────────────────────────────────────

it('cancels a routine by setting is_active to false', function () {
    $routine = Routine::create([
        'user_id' => $this->user->id,
        'connector' => 'telegram',
        'key' => 'user-123',
        'prompt' => 'Cancel me',
        'cron' => '0 9 * * *',
        'is_active' => true,
    ]);

    $result = routineTool()->handle(routineRequest([
        'operation' => 'cancel',
        'id' => (string) $routine->id,
    ]));

    expect(Routine::first()->is_active)->toBeFalse();
    expect($result)->toContain('cancelled');
});

it('returns not-found when cancelling a non-existent routine', function () {
    $result = routineTool()->handle(routineRequest([
        'operation' => 'cancel',
        'id' => '9999',
    ]));

    expect($result)->toContain('not found');
});

it('returns an error when cancel is called without an id', function () {
    $result = routineTool()->handle(routineRequest(['operation' => 'cancel']));

    expect($result)->toContain('"id" parameter is required');
});
