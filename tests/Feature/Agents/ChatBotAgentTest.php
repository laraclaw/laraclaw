<?php

use Illuminate\Support\Facades\File;
use Laraclaw\Agents\ChatBotAgent;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Account;
use Laraclaw\Models\Thread;
use Laraclaw\Skills\SkillRegistry;
use Laraclaw\Tools\ToolRegistry;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Providers\Tools\WebSearch;

function makeAgent(array $config = []): ChatBotAgent
{
    $message = new IncomingMessage(
        text: 'hello',
        connector: ConnectorType::Terminal,
        key: 'user-1',
        isDirectMessage: true,
    );

    Account::create([
        'connector' => ConnectorType::Terminal,
        'account' => 'user-1',
        'user_id' => test()->user->id,
    ]);

    $thread = Thread::forMessage($message);

    foreach ($config as $key => $value) {
        config([$key => $value]);
    }

    return new ChatBotAgent(
        message: $message,
        skillRegistry: app(SkillRegistry::class),
        toolRegistry: app(ToolRegistry::class),
        thread: $thread,
    );
}

beforeEach(function () {
    $this->user = $this->createUser();
    config([
        'laraclaw.auth.admin_user_id' => $this->user->id,
        // Use Mistral as the default in tests because it does not implement
        // SupportsWebSearch, so we can assert WebSearch is opted out.
        'ai.default' => 'mistral',
    ]);
});

it('implements every agent contract the prompt loop expects', function () {
    $agent = makeAgent();

    expect($agent)
        ->toBeInstanceOf(Agent::class)
        ->toBeInstanceOf(Conversational::class)
        ->toBeInstanceOf(HasMiddleware::class)
        ->toBeInstanceOf(HasProviderOptions::class)
        ->toBeInstanceOf(HasTools::class);
});

it('returns cache_control for Anthropic and prompt_cache_key for OpenAI', function () {
    $agent = makeAgent();

    expect($agent->providerOptions(Lab::Anthropic))
        ->toBe(['cache_control' => ['type' => 'ephemeral']]);
    expect($agent->providerOptions(Lab::OpenAI))
        ->toBe(['prompt_cache_key' => 'laraclaw-chatbot']);
    expect($agent->providerOptions(Lab::Gemini))->toBe([]);
    expect($agent->providerOptions('unknown-driver'))->toBe([]);
});

it('builds instructions from the default prompt and grounds them in time', function () {
    $agent = makeAgent();

    $instructions = $agent->instructions();

    expect($instructions)
        ->toContain('Current date and time:')
        ->toContain(date('Y'));
});

it('appends the persona when a thread persona is set', function () {
    $personas = sys_get_temp_dir() . '/laraclaw-personas-' . uniqid();
    File::makeDirectory($personas);
    File::put("{$personas}/pirate.md", "Speak like a pirate.\n");

    $agent = makeAgent([
        'laraclaw.personas.path' => $personas,
    ]);
    $agent->thread->update(['persona' => 'pirate']);
    // Refresh in-memory state so resolvePersona reads the new value
    $agent->thread->refresh();

    expect($agent->instructions())->toContain('Speak like a pirate.');

    File::deleteDirectory($personas);
});

it('ignores a persona that does not exist on disk', function () {
    $personas = sys_get_temp_dir() . '/laraclaw-personas-' . uniqid();
    File::makeDirectory($personas);

    $agent = makeAgent(['laraclaw.personas.path' => $personas]);
    $agent->thread->update(['persona' => 'unknown-persona']);
    $agent->thread->refresh();

    // Falls back to base prompt only — no persona content appended
    expect($agent->instructions())->not->toContain('unknown-persona');

    File::deleteDirectory($personas);
});

it('exposes the always-on tools regardless of config', function () {
    $tools = collect(makeAgent()->tools())->map(fn ($t): string => $t::class);

    expect($tools)->toContain(
        \Laraclaw\Tools\UseSkill::class,
        \Laraclaw\Tools\Persona::class,
        \Laraclaw\Tools\ImageManager::class,
        \Laraclaw\Tools\FileManager::class,
        \Laraclaw\Tools\ReminderManager::class,
        \Laraclaw\Tools\HeartbeatManager::class,
        \Laraclaw\Tools\WebRequest::class,
    );
});

it('omits opt-in tools when their config flags are false', function () {
    $agent = makeAgent([
        'laraclaw.tools.bash.enabled' => false,
        'laraclaw.tools.tinker.enabled' => false,
        'laraclaw.tools.tts.enabled' => false,
        'laraclaw.memory.enabled' => false,
        'laraclaw.connectors.email.enabled' => false,
    ]);

    $tools = collect($agent->tools())->map(fn ($t): string => $t::class);

    expect($tools)->not->toContain(
        \Laraclaw\Tools\Bash::class,
        \Laraclaw\Tools\Tinker::class,
        \Laraclaw\Tools\TextToSpeech::class,
        \Laraclaw\Tools\MemoryManager::class,
        \Laraclaw\Tools\EmailManager::class,
    );
});

it('adds the opt-in tools when their config flags are true', function () {
    $tools = collect(makeAgent([
        'laraclaw.tools.bash.enabled' => true,
        'laraclaw.tools.tinker.enabled' => true,
        'laraclaw.tools.tts.enabled' => true,
    ])->tools())->map(fn ($t): string => $t::class);

    expect($tools)->toContain(
        \Laraclaw\Tools\Bash::class,
        \Laraclaw\Tools\Tinker::class,
        \Laraclaw\Tools\TextToSpeech::class,
    );
});

it('omits WebSearch when the active provider does not support it', function () {
    $tools = collect(makeAgent()->tools())->map(fn ($t): string => $t::class);

    expect($tools)->not->toContain(WebSearch::class);
});

it('adds WebSearch when the active provider supports it', function () {
    $tools = collect(makeAgent(['ai.default' => 'anthropic'])->tools())
        ->map(fn ($t): string => $t::class);

    expect($tools)->toContain(WebSearch::class);
});

it('returns the TranscribeAudio middleware', function () {
    $middleware = makeAgent()->middleware();

    expect($middleware)->toHaveCount(1);
    expect($middleware[0])->toBeInstanceOf(Laraclaw\Agents\Middleware\TranscribeAudio::class);
});
