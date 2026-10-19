<?php

use Illuminate\Support\Facades\Http;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Tools\WebRequest;
use Laravel\Ai\Tools\Request;

function webRequest(array $data): Request
{
    return new Request($data, 'call_test');
}

function webTool(): WebRequest
{
    return new WebRequest(new IncomingMessage(
        text: 'test',
        connector: ConnectorType::Terminal,
        key: 'user',
        isDirectMessage: true,
    ));
}

beforeEach(function () {
    Http::preventStrayRequests();
});

it('rejects an invalid URL', function () {
    $result = webTool()->handle(webRequest([
        'operation' => 'get',
        'url' => 'not a url',
    ]));

    expect($result)->toContain('Invalid URL');
});

it('blocks loopback addresses', function () {
    $result = webTool()->handle(webRequest([
        'operation' => 'get',
        'url' => 'http://127.0.0.1/admin',
    ]));

    expect($result)->toBe('Requests to private/internal network addresses are not allowed.');
});

it('blocks private RFC1918 addresses', function () {
    $result = webTool()->handle(webRequest([
        'operation' => 'get',
        'url' => 'http://10.0.0.1/',
    ]));

    expect($result)->toBe('Requests to private/internal network addresses are not allowed.');
});

it('blocks link-local metadata addresses', function () {
    $result = webTool()->handle(webRequest([
        'operation' => 'get',
        'url' => 'http://169.254.169.254/latest/meta-data/',
    ]));

    expect($result)->toBe('Requests to private/internal network addresses are not allowed.');
});

it('returns an error when the operation is unknown', function () {
    Http::fake(['example.com/*' => Http::response('ok')]);

    $result = webTool()->handle(webRequest([
        'operation' => 'options',
        'url' => 'https://example.com/',
    ]));

    expect($result)->toContain("Unknown operation 'options'");
});

it('issues a GET and serializes the response as JSON', function () {
    Http::fake([
        'example.com/data' => Http::response('{"hello":"world"}', 200, [
            'Content-Type' => 'application/json',
        ]),
    ]);

    $result = webTool()->handle(webRequest([
        'operation' => 'get',
        'url' => 'https://example.com/data',
    ]));

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe(200);
    expect(array_change_key_case($decoded['headers']))->toHaveKey('content-type');
    expect($decoded['body'])->toBe('{"hello":"world"}');
});

it('issues a HEAD and returns only status and headers', function () {
    Http::fake([
        'example.com/h' => Http::response('', 204, ['X-Request-Id' => 'abc']),
    ]);

    $result = webTool()->handle(webRequest([
        'operation' => 'head',
        'url' => 'https://example.com/h',
    ]));

    $decoded = json_decode($result, true);
    expect($decoded)->toHaveKeys(['status', 'headers']);
    expect($decoded)->not->toHaveKey('body');
    expect($decoded['status'])->toBe(204);
});

it('sends a JSON body with application/json content type', function () {
    Http::fake(['example.com/post' => Http::response('ok', 201)]);

    $result = webTool()->handle(webRequest([
        'operation' => 'post',
        'url' => 'https://example.com/post',
        'body' => '{"a":1}',
    ]));

    expect(json_decode($result, true)['status'])->toBe(201);

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->body() === '{"a":1}'
        && $request->header('Content-Type')[0] === 'application/json');
});

it('sends a non-JSON body with text/plain content type', function () {
    Http::fake(['example.com/post' => Http::response('ok')]);

    webTool()->handle(webRequest([
        'operation' => 'post',
        'url' => 'https://example.com/post',
        'body' => 'plain old text',
    ]));

    Http::assertSent(fn ($request): bool => $request->header('Content-Type')[0] === 'text/plain');
});

it('forwards custom request headers', function () {
    Http::fake(['example.com/*' => Http::response('ok')]);

    webTool()->handle(webRequest([
        'operation' => 'get',
        'url' => 'https://example.com/x',
        'headers' => ['X-Token' => 'shh'],
    ]));

    Http::assertSent(fn ($request): bool => $request->header('X-Token')[0] === 'shh');
});

it('truncates response bodies larger than 100KB', function () {
    $oversized = str_repeat('a', 110 * 1024);
    Http::fake(['example.com/big' => Http::response($oversized)]);

    $result = webTool()->handle(webRequest([
        'operation' => 'get',
        'url' => 'https://example.com/big',
    ]));

    $decoded = json_decode($result, true);
    expect($decoded['body'])->toContain('[Truncated: response exceeds 100KB]');
    expect(strlen($decoded['body']))->toBeLessThan(strlen($oversized));
});

it('returns a friendly error when the HTTP request throws', function () {
    Http::fake(fn () => throw new RuntimeException('connection refused'));

    $result = webTool()->handle(webRequest([
        'operation' => 'get',
        'url' => 'https://example.com/dead',
    ]));

    expect($result)->toContain('HTTP request failed: connection refused');
});

it('blocks a scheme outside the http allowlist', function () {
    $result = webTool()->handle(webRequest([
        'operation' => 'get',
        'url' => 'file:///etc/passwd',
    ]));

    expect($result)->toContain('Only http and https URLs are allowed');
});

it('blocks an IPv4 address wrapped in IPv6 form', function () {
    $result = webTool()->handle(webRequest([
        'operation' => 'get',
        'url' => 'http://[::ffff:127.0.0.1]/admin',
    ]));

    expect($result)->toBe('Requests to private/internal network addresses are not allowed.');
});

it('blocks a redirect that points at a private address', function () {
    Http::fake([
        'example.com/start' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
    ]);

    $result = webTool()->handle(webRequest([
        'operation' => 'get',
        'url' => 'https://example.com/start',
    ]));

    expect($result)->toBe('Requests to private/internal network addresses are not allowed.');
    Http::assertSentCount(1);
});

it('blocks a redirect that walks back to loopback', function () {
    Http::fake([
        'example.com/start' => Http::response('', 301, ['Location' => 'http://127.0.0.1:8080/internal']),
    ]);

    $result = webTool()->handle(webRequest([
        'operation' => 'get',
        'url' => 'https://example.com/start',
    ]));

    expect($result)->toBe('Requests to private/internal network addresses are not allowed.');
});

it('follows a redirect to a public address and returns the final response', function () {
    Http::fake([
        'example.com/start' => Http::response('', 302, ['Location' => '/final']),
        'example.com/final' => Http::response('landed', 200),
    ]);

    $result = webTool()->handle(webRequest([
        'operation' => 'get',
        'url' => 'https://example.com/start',
    ]));

    $decoded = json_decode($result, true);
    expect($decoded['status'])->toBe(200);
    expect($decoded['body'])->toBe('landed');
    Http::assertSentCount(2);
});

it('turns a redirected POST into a GET and drops the body', function () {
    Http::fake([
        'example.com/start' => Http::response('', 303, ['Location' => 'https://example.com/final']),
        'example.com/final' => Http::response('ok', 200),
    ]);

    webTool()->handle(webRequest([
        'operation' => 'post',
        'url' => 'https://example.com/start',
        'body' => '{"a":1}',
    ]));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://example.com/final'
        && $request->method() === 'GET'
        && $request->body() === '');
});

it('gives up once the redirect limit is reached', function () {
    config(['laraclaw.http.max_redirects' => 2]);

    Http::fake([
        'example.com/*' => Http::response('', 302, ['Location' => 'https://example.com/loop']),
    ]);

    $result = webTool()->handle(webRequest([
        'operation' => 'get',
        'url' => 'https://example.com/loop',
    ]));

    expect($result)->toContain('Gave up after following 2 redirects');
});
