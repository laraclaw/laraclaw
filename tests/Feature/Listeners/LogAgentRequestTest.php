<?php

use App\Ai\Agents\ChatBot;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

function fakeAgentPromptedEvent(string $prompt = 'Hello', string $responseText = 'Hi there'): AgentPrompted
{
    $provider = Mockery::mock(TextProvider::class);
    $provider->shouldReceive('name')->andReturn('openai');
    $provider->shouldReceive('defaultTextModel')->andReturn('gpt-4o');

    $agent = new ChatBot;
    $agentPrompt = new AgentPrompt($agent, $prompt, [], $provider, 'gpt-4o', 60);

    $response = new AgentResponse(
        'inv-123',
        $responseText,
        new Usage(100, 50),
        new Meta('openai', 'gpt-4o'),
    );

    return new AgentPrompted('inv-123', $agentPrompt, $response);
}

afterEach(function () {
    File::deleteDirectory(storage_path('logs/laraclaw/requests'));
});

it('creates request and response json files', function () {
    $event = fakeAgentPromptedEvent('What is Laravel?', 'A PHP framework.');

    (new \App\Listeners\LogAgentRequest)->handle($event);

    $dirs = File::directories(storage_path('logs/laraclaw/requests/no-conversation'));
    expect($dirs)->toHaveCount(1);

    $requestFile = $dirs[0] . '/request.json';
    $responseFile = $dirs[0] . '/response.json';

    expect(File::exists($requestFile))->toBeTrue()
        ->and(File::exists($responseFile))->toBeTrue();

    $request = json_decode(File::get($requestFile), true);
    expect($request['prompt'])->toBe('What is Laravel?')
        ->and($request['provider'])->toBe('openai')
        ->and($request['model'])->toBe('gpt-4o');

    $response = json_decode(File::get($responseFile), true);
    expect($response['text'])->toBe('A PHP framework.')
        ->and($response['usage']['prompt_tokens'])->toBe(100)
        ->and($response['usage']['completion_tokens'])->toBe(50);
});
