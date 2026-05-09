<?php

use Illuminate\Http\Request;
use Laraclaw\Http\Middleware\VerifySlackSignature;

beforeEach(function () {
    config(['laraclaw.connectors.slack.signing_secret' => 'test-secret']);
});

function makeSlackRequest(string $body, ?string $timestamp = null, ?string $secret = null): Request
{
    $timestamp ??= (string) time();
    $secret ??= 'test-secret';

    $baseString = "v0:{$timestamp}:{$body}";
    $signature = 'v0=' . hash_hmac('sha256', $baseString, $secret);

    return Request::create('/slack/webhook', 'POST', content: $body, server: [
        'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $timestamp,
        'HTTP_X_SLACK_SIGNATURE' => $signature,
    ]);
}

it('passes valid requests through', function () {
    $request = makeSlackRequest('{"event":"test"}');
    $middleware = new VerifySlackSignature;

    $response = $middleware->handle($request, fn () => response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('rejects requests with missing headers', function () {
    $request = Request::create('/slack/webhook', 'POST');
    $middleware = new VerifySlackSignature;

    $middleware->handle($request, fn () => response('ok'));
})->throws(\Symfony\Component\HttpKernel\Exception\HttpException::class);

it('rejects requests with stale timestamps', function () {
    $request = makeSlackRequest('body', timestamp: (string) (time() - 600));
    $middleware = new VerifySlackSignature;

    $middleware->handle($request, fn () => response('ok'));
})->throws(\Symfony\Component\HttpKernel\Exception\HttpException::class);

it('rejects requests with invalid signatures', function () {
    $request = makeSlackRequest('body', secret: 'wrong-secret');
    $middleware = new VerifySlackSignature;

    $middleware->handle($request, fn () => response('ok'));
})->throws(\Symfony\Component\HttpKernel\Exception\HttpException::class);
