<?php

use App\Jobs\ProcessMessage;
use Illuminate\Support\Facades\Queue;

function slackSignature(string $body, ?int $timestamp = null, ?string $secret = null): array
{
    $timestamp ??= time();
    $secret ??= config('laraclaw.channels.slack.signing_secret');
    $baseString = "v0:{$timestamp}:{$body}";
    $signature = 'v0=' . hash_hmac('sha256', $baseString, $secret);

    return [
        'X-Slack-Request-Timestamp' => (string) $timestamp,
        'X-Slack-Signature' => $signature,
    ];
}

beforeEach(function () {
    config(['laraclaw.channels.slack.signing_secret' => 'test-signing-secret']);
});

it('responds to url verification challenge', function () {
    $body = json_encode([
        'type' => 'url_verification',
        'challenge' => 'test-challenge-string',
    ]);

    $response = $this->call(
        'POST',
        '/webhook/slack',
        [],
        [],
        [],
        array_merge(
            ['CONTENT_TYPE' => 'application/json'],
            collect(slackSignature($body))->mapWithKeys(fn ($v, $k) => ['HTTP_' . str_replace('-', '_', strtoupper($k)) => $v])->all(),
        ),
        $body,
    );

    $response->assertOk();
    $response->assertJson(['challenge' => 'test-challenge-string']);
});

it('dispatches process message job for valid message events', function () {
    Queue::fake();

    $body = json_encode([
        'type' => 'event_callback',
        'event' => [
            'type' => 'message',
            'user' => 'U123',
            'text' => 'hello',
            'ts' => '1234567890.123456',
            'channel' => 'C456',
        ],
    ]);

    $response = $this->call(
        'POST',
        '/webhook/slack',
        [],
        [],
        [],
        array_merge(
            ['CONTENT_TYPE' => 'application/json'],
            collect(slackSignature($body))->mapWithKeys(fn ($v, $k) => ['HTTP_' . str_replace('-', '_', strtoupper($k)) => $v])->all(),
        ),
        $body,
    );

    $response->assertNoContent();
    Queue::assertPushed(ProcessMessage::class);
});

it('ignores bot messages', function () {
    Queue::fake();

    $body = json_encode([
        'type' => 'event_callback',
        'event' => [
            'type' => 'message',
            'bot_id' => 'B123',
            'text' => 'bot reply',
            'ts' => '1234567890.123456',
            'channel' => 'C456',
        ],
    ]);

    $response = $this->call(
        'POST',
        '/webhook/slack',
        [],
        [],
        [],
        array_merge(
            ['CONTENT_TYPE' => 'application/json'],
            collect(slackSignature($body))->mapWithKeys(fn ($v, $k) => ['HTTP_' . str_replace('-', '_', strtoupper($k)) => $v])->all(),
        ),
        $body,
    );

    $response->assertNoContent();
    Queue::assertNotPushed(ProcessMessage::class);
});

it('rejects requests with invalid signature', function () {
    $body = json_encode(['type' => 'event_callback', 'event' => ['type' => 'message']]);

    $response = $this->call(
        'POST',
        '/webhook/slack',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SLACK_REQUEST_TIMESTAMP' => (string) time(),
            'HTTP_X_SLACK_SIGNATURE' => 'v0=invalidsignature',
        ],
        $body,
    );

    $response->assertForbidden();
});

it('rejects requests with old timestamp', function () {
    $body = json_encode(['type' => 'event_callback']);
    $oldTimestamp = time() - 600;

    $response = $this->call(
        'POST',
        '/webhook/slack',
        [],
        [],
        [],
        array_merge(
            ['CONTENT_TYPE' => 'application/json'],
            collect(slackSignature($body, $oldTimestamp))->mapWithKeys(fn ($v, $k) => ['HTTP_' . str_replace('-', '_', strtoupper($k)) => $v])->all(),
        ),
        $body,
    );

    $response->assertForbidden();
});

it('ignores non-message events', function () {
    Queue::fake();

    $body = json_encode([
        'type' => 'event_callback',
        'event' => [
            'type' => 'reaction_added',
            'user' => 'U123',
        ],
    ]);

    $response = $this->call(
        'POST',
        '/webhook/slack',
        [],
        [],
        [],
        array_merge(
            ['CONTENT_TYPE' => 'application/json'],
            collect(slackSignature($body))->mapWithKeys(fn ($v, $k) => ['HTTP_' . str_replace('-', '_', strtoupper($k)) => $v])->all(),
        ),
        $body,
    );

    $response->assertNoContent();
    Queue::assertNotPushed(ProcessMessage::class);
});
