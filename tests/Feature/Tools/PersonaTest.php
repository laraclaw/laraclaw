<?php

use Illuminate\Support\Facades\File;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Thread;
use Laraclaw\Tools\Persona;
use Laravel\Ai\Tools\Request;

function personaRequest(array $data): Request
{
    return new Request($data, 'call_test');
}

beforeEach(function () {
    $this->path = sys_get_temp_dir() . '/laraclaw-personas-' . uniqid();
    File::makeDirectory($this->path);
    File::put("{$this->path}/pirate.md", 'Speak like a pirate.');
    File::put("{$this->path}/scientist.md", 'Speak like a scientist.');

    config(['laraclaw.personas.path' => $this->path]);

    $message = new IncomingMessage(
        text: 'test',
        connector: ConnectorType::Terminal,
        key: 'user',
        isDirectMessage: true,
    );
    $this->thread = Thread::forMessage($message);
});

afterEach(function () {
    File::deleteDirectory($this->path);
});

it('lists available personas alphabetically', function () {
    $tool = new Persona($this->thread);

    $result = $tool->handle(personaRequest(['operation' => 'list']));

    expect((string) $result)->toBe('Available personas: pirate, scientist');
});

it('switches to a known persona and persists it on the thread', function () {
    $tool = new Persona($this->thread);

    $result = $tool->handle(personaRequest(['operation' => 'switch', 'persona' => 'pirate']));

    expect((string) $result)->toContain("switched to 'pirate'");
    expect($this->thread->fresh()->persona)->toBe('pirate');
});

it('refuses to switch to an unknown persona', function () {
    $tool = new Persona($this->thread);

    $result = $tool->handle(personaRequest(['operation' => 'switch', 'persona' => 'ninja']));

    expect((string) $result)->toContain("Unknown persona 'ninja'");
    expect($this->thread->fresh()->persona)->toBeNull();
});

it('refuses to switch without a persona name', function () {
    $tool = new Persona($this->thread);

    $result = $tool->handle(personaRequest(['operation' => 'switch']));

    expect((string) $result)->toContain('"persona" parameter is required');
});

it('clears the persona on the thread', function () {
    $this->thread->update(['persona' => 'pirate']);

    $tool = new Persona($this->thread);

    $result = $tool->handle(personaRequest(['operation' => 'clear']));

    expect((string) $result)->toContain('Persona cleared');
    expect($this->thread->fresh()->persona)->toBeNull();
});

it('rejects unknown operations', function () {
    $tool = new Persona($this->thread);

    $result = $tool->handle(personaRequest(['operation' => 'merge']));

    expect((string) $result)->toContain("Unknown operation 'merge'");
});

it('reports an empty directory cleanly', function () {
    $emptyPath = sys_get_temp_dir() . '/laraclaw-personas-empty-' . uniqid();
    File::makeDirectory($emptyPath);
    config(['laraclaw.personas.path' => $emptyPath]);

    $tool = new Persona($this->thread);

    expect((string) $tool->handle(personaRequest(['operation' => 'list'])))
        ->toContain('No persona files found');

    File::deleteDirectory($emptyPath);
});
