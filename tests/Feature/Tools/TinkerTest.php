<?php

use LaraClaw\Tools\Tinker;
use Laravel\Ai\Tools\Request;

function tinkerRequest(array $data): Request
{
    $mock = Mockery::mock(Request::class);
    $mock->allows('offsetGet')->andReturnUsing(fn ($key) => $data[$key] ?? null);
    $mock->allows('offsetExists')->andReturnUsing(fn ($key) => array_key_exists($key, $data));

    return $mock;
}

it('evaluates simple PHP code and returns output', function () {
    $result = (new Tinker)->handle(tinkerRequest([
        'code' => 'echo "hello world";',
    ]));

    $decoded = json_decode($result, true);

    expect($decoded['exit_code'])->toBe(0);
    expect($decoded['output'])->toContain('hello world');
});

it('can access Laravel helpers', function () {
    $result = (new Tinker)->handle(tinkerRequest([
        'code' => 'echo app()->version();',
    ]));

    $decoded = json_decode($result, true);

    expect($decoded['exit_code'])->toBe(0);
    expect($decoded['output'])->not->toBeEmpty();
});

it('returns an error when code is empty', function () {
    $result = (new Tinker)->handle(tinkerRequest(['code' => '']));

    expect($result)->toContain('"code" parameter is required');
});

it('returns an error when code is missing', function () {
    $result = (new Tinker)->handle(tinkerRequest([]));

    expect($result)->toContain('"code" parameter is required');
});

it('returns a note when code produces no output', function () {
    $result = (new Tinker)->handle(tinkerRequest([
        'code' => '$x = 1;',
    ]));

    $decoded = json_decode($result, true);

    expect($decoded['exit_code'])->toBe(0);
});
