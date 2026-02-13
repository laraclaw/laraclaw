<?php

use App\Ai\Agents\ChatBot;
use App\Ai\Tools\UseSkill;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\ToolInvoked;

it('logs tool invocations to laraclaw channel', function () {
    Log::shouldReceive('channel')
        ->with('laraclaw')
        ->once()
        ->andReturnSelf();

    Log::shouldReceive('info')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return str_contains($message, 'UseSkill')
                && $context['arguments']['skill'] === 'deslop'
                && isset($context['invocation_id']);
        });

    $event = new ToolInvoked(
        invocationId: 'inv-123',
        toolInvocationId: 'tool-456',
        agent: new ChatBot,
        tool: Mockery::mock(UseSkill::class),
        arguments: ['skill' => 'deslop'],
        result: 'Review the text and remove AI patterns...',
    );

    (new \App\Listeners\LogToolInvocation)->handle($event);
});
