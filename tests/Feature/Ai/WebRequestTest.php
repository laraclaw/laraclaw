<?php

use App\Ai\Tools\WebRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

function wr(array $params): string
{
    return (string) (new WebRequest)->handle(new Request($params));
}

// — GET —

it('makes a GET request', function () {
    Http::fake(['https://example.com/api' => Http::response('{"ok":true}', 200)]);

    $result = wr(['method' => 'get', 'url' => 'https://example.com/api']);
    $data = json_decode($result, true);

    expect($data['status'])->toBe(200)
        ->and($data['body'])->toContain('"ok":true');
});

// — POST —

it('makes a POST request with body', function () {
    Http::fake(['https://example.com/api' => Http::response('created', 201)]);

    $result = wr([
        'method' => 'post',
        'url' => 'https://example.com/api',
        'body' => '{"name":"test"}',
    ]);
    $data = json_decode($result, true);

    expect($data['status'])->toBe(201)
        ->and($data['body'])->toBe('created');
});

// — Headers —

it('sends custom headers', function () {
    Http::fake(['https://example.com/*' => Http::response('ok')]);

    $result = wr([
        'method' => 'get',
        'url' => 'https://example.com/api',
        'headers' => '{"X-Custom":"value"}',
    ]);

    Http::assertSent(fn ($request) => $request->hasHeader('X-Custom', 'value'));
});

// — Truncation —

it('truncates large responses', function () {
    Http::fake(['https://example.com/*' => Http::response(str_repeat('x', 120_000))]);

    $result = wr(['method' => 'get', 'url' => 'https://example.com/big']);
    $data = json_decode($result, true);

    expect($data['body'])->toContain('[Truncated');
});

// — Validation —

it('rejects unknown methods', function () {
    $result = wr(['method' => 'OPTIONS', 'url' => 'https://example.com']);

    expect($result)->toContain('Unknown method');
});

it('rejects invalid URLs', function () {
    $result = wr(['method' => 'get', 'url' => 'not-a-url']);

    expect($result)->toContain('Invalid URL');
});

it('blocks requests to localhost', function () {
    $result = wr(['method' => 'get', 'url' => 'http://localhost/admin']);

    expect($result)->toContain('private/internal');
});

it('blocks requests to 127.0.0.1', function () {
    $result = wr(['method' => 'get', 'url' => 'http://127.0.0.1:8080/']);

    expect($result)->toContain('private/internal');
});

// — Header filtering —

it('only returns relevant response headers', function () {
    Http::fake(['https://example.com/*' => Http::response('ok', 200, [
        'Content-Type' => 'text/plain',
        'X-Internal' => 'secret',
        'Location' => '/redirect',
    ])]);

    $result = wr(['method' => 'get', 'url' => 'https://example.com/api']);
    $data = json_decode($result, true);

    expect($data['headers'])->toHaveKey('Content-Type')
        ->and($data['headers'])->toHaveKey('Location')
        ->and($data['headers'])->not->toHaveKey('X-Internal');
});
