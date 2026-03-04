<?php

use LaraClaw\Tools\Bash;
use Laravel\Ai\Tools\Request;

function bashRequest(array $data): Request
{
    $mock = Mockery::mock(Request::class);
    $mock->allows('offsetGet')->andReturnUsing(fn ($key) => $data[$key] ?? null);
    $mock->allows('offsetExists')->andReturnUsing(fn ($key) => array_key_exists($key, $data));

    return $mock;
}

it('executes a simple command and returns stdout', function () {
    $result = (new Bash)->handle(bashRequest([
        'command' => 'echo "hello world"',
    ]));

    $decoded = json_decode($result, true);

    expect($decoded['exit_code'])->toBe(0);
    expect($decoded['stdout'])->toContain('hello world');
});

it('returns stderr when a command writes to stderr', function () {
    $result = (new Bash)->handle(bashRequest([
        'command' => 'echo "error output" >&2',
    ]));

    $decoded = json_decode($result, true);

    expect($decoded['stderr'])->toContain('error output');
});

it('returns a non-zero exit code for failing commands', function () {
    $result = (new Bash)->handle(bashRequest([
        'command' => 'exit 42',
    ]));

    $decoded = json_decode($result, true);

    expect($decoded['exit_code'])->toBe(42);
});

it('returns an error when command is empty', function () {
    $result = (new Bash)->handle(bashRequest(['command' => '']));

    expect($result)->toContain('"command" parameter is required');
});

it('returns an error when command is missing', function () {
    $result = (new Bash)->handle(bashRequest([]));

    expect($result)->toContain('"command" parameter is required');
});

it('executes a multi-line script', function () {
    $result = (new Bash)->handle(bashRequest([
        'command' => "A=hello\nB=world\necho \"\$A \$B\"",
    ]));

    $decoded = json_decode($result, true);

    expect($decoded['exit_code'])->toBe(0);
    expect($decoded['stdout'])->toContain('hello world');
});

it('returns a note when command produces no output', function () {
    $result = (new Bash)->handle(bashRequest(['command' => 'true']));

    $decoded = json_decode($result, true);

    expect($decoded['exit_code'])->toBe(0);
    expect($decoded['note'])->toContain('no output');
});
