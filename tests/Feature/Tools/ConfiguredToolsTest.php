<?php

use Illuminate\Support\Facades\Log;
use Laraclaw\Agents\ChatBotAgent;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Account;
use Laraclaw\Models\Thread;
use Laraclaw\Services\DatabaseSchemaReader;
use Laraclaw\Skills\SkillRegistry;
use Laraclaw\Tests\Fixtures\OrderManager;
use Laraclaw\Tests\Fixtures\ThreadAwareTool;
use Laraclaw\Tools\ToolRegistry;
use Laravel\Ai\Tools\Request;

function agentWithTools(array $custom): ChatBotAgent
{
    config(['laraclaw.tools.custom' => $custom]);

    $message = new IncomingMessage(
        text: 'hello',
        connector: ConnectorType::Terminal,
        key: 'user-1',
        isDirectMessage: true,
    );

    // Called twice in one test when comparing tool counts, so it has to be idempotent.
    Account::firstOrCreate([
        'connector' => ConnectorType::Terminal,
        'account' => 'user-1',
        'user_id' => test()->user->id,
    ]);

    return new ChatBotAgent(
        message: $message,
        skillRegistry: app(SkillRegistry::class),
        toolRegistry: app(ToolRegistry::class),
        thread: Thread::forMessage($message),
        schemaReader: app(DatabaseSchemaReader::class),
    );
}

beforeEach(function () {
    $this->user = $this->createUser();
    config([
        'laraclaw.auth.admin_user_id' => $this->user->id,
        'ai.default' => 'mistral',
    ]);
});

it('builds a tool listed in config and hands it the message', function () {
    $agent = agentWithTools([OrderManager::class]);

    $tool = collect($agent->tools())->first(fn ($t): bool => $t instanceof OrderManager);

    expect($tool)->not->toBeNull()
        ->and($tool->handle(new Request(['operation' => 'find', 'order_id' => '123'], 'c1')))
        ->toBe('Order 123: shipped');
});

it('hands a configured tool the thread when it asks for one', function () {
    $agent = agentWithTools([ThreadAwareTool::class]);

    $tool = collect($agent->tools())->first(fn ($t): bool => $t instanceof ThreadAwareTool);

    expect($tool->thread->key)->toBe('user-1');
});

it('keeps approval gating on a configured tool', function () {
    $agent = agentWithTools([OrderManager::class]);

    $tool = collect($agent->tools())->first(fn ($t): bool => $t instanceof OrderManager);

    expect($tool->shouldRequestApproval(new Request(['operation' => 'refund', 'order_id' => '123'], 'c2'))->reason)
        ->toBe('Refund order 123?')
        ->and($tool->shouldRequestApproval(new Request(['operation' => 'find', 'order_id' => '123'], 'c3')))
        ->toBeNull();
});

it('warns and carries on when a configured tool does not exist', function () {
    // A typo should cost the user one tool, not every message.
    Log::spy();

    $agent = agentWithTools(['App\Ai\Tools\Typo']);

    expect($agent->tools())->not->toBeEmpty();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $m): bool => str_contains($m, 'does not exist'))
        ->once();
});

it('warns and carries on when a configured class is not a tool', function () {
    Log::spy();

    $agent = agentWithTools([Thread::class]);

    expect(collect($agent->tools())->map(fn ($t): string => $t::class))
        ->not->toContain(Thread::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $m): bool => str_contains($m, 'does not implement'))
        ->once();
});

it('adds nothing when no custom tools are configured', function () {
    $baseline = count(agentWithTools([])->tools());

    expect(count(agentWithTools([OrderManager::class])->tools()))
        ->toBe($baseline + 1);
});
