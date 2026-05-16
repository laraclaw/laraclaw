<?php

use Illuminate\Support\Facades\Artisan;
use Laraclaw\Tools\Tinker;
use Laravel\Ai\Tools\Request;

function tinkerRequest(array $data): Request
{
    $mock = Mockery::mock(Request::class);
    $mock->allows('offsetGet')->andReturnUsing(fn ($key) => $data[$key] ?? null);
    $mock->allows('offsetExists')->andReturnUsing(fn ($key) => array_key_exists($key, $data));

    return $mock;
}

function tinkerAvailable(): bool
{
    return collect(Artisan::all())->has('tinker');
}

it('evaluates simple PHP code and returns output', function () {
    $result = (new Tinker)->handle(tinkerRequest([
        'code' => 'echo "hello world";',
    ]));

    $decoded = json_decode($result, true);

    expect($decoded['exit_code'])->toBe(0);
    expect($decoded['output'])->toContain('hello world');
})->skip(fn () => ! tinkerAvailable(), 'Tinker command not registered');

it('can access Laravel helpers', function () {
    $result = (new Tinker)->handle(tinkerRequest([
        'code' => 'echo app()->version();',
    ]));

    $decoded = json_decode($result, true);

    expect($decoded['exit_code'])->toBe(0);
    expect($decoded['output'])->not->toBeEmpty();
})->skip(fn () => ! tinkerAvailable(), 'Tinker command not registered');

it('returns an error when code is empty', function () {
    $result = (new Tinker)->handle(tinkerRequest(['code' => '']));

    expect($result)->toContain('"code" parameter is required');
});

it('returns an error when code is missing', function () {
    $result = (new Tinker)->handle(tinkerRequest([]));

    expect($result)->toContain('"code" parameter is required');
});

it('returns a friendly error when the tinker command is not registered', function () {
    $result = (new Tinker)->handle(tinkerRequest(['code' => 'echo 1;']));

    expect($result)->toContain('Tinker command is not registered');
})->skip(fn () => tinkerAvailable(), 'Tinker command is registered in this environment');

it('returns a note when code produces no output', function () {
    $result = (new Tinker)->handle(tinkerRequest([
        'code' => '$x = 1;',
    ]));

    $decoded = json_decode($result, true);

    expect($decoded['exit_code'])->toBe(0);
    expect($decoded['note'])->toContain('no output');
})->skip(fn () => ! tinkerAvailable(), 'Tinker command not registered');
