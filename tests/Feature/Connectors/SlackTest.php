<?php

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use LaraClaw\Connectors\Slack;
use LaraClaw\Enums\ConnectorType;
use LaraClaw\Models\UserAccount;

function slackRequest(array $payload): Request
{
    return Request::create(
        '/slack/webhook',
        'POST',
        server: ['CONTENT_TYPE' => 'application/json'],
        content: json_encode($payload),
    );
}

function validPayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'event_callback',
        'event' => [
            'type' => 'message',
            'channel' => 'C123',
            'text' => 'hello',
        ],
    ], $overrides);
}

function expectValidationCode(Request $request, string $code): void
{
    try {
        Slack::validateEvent($request);
        test()->fail("Expected ValidationException with code '{$code}' but none was thrown.");
    } catch (ValidationException $e) {
        expect($e->validator->errors()->first())->toBe($code);
    }
}

function registerSlackAccount(string $slackUserId): void
{
    $user = test()->createUser();

    UserAccount::create([
        'user_id' => $user->getAuthIdentifier(),
        'connector' => ConnectorType::Slack,
        'account' => $slackUserId,
    ]);
}

beforeEach(function () {
    config([
        'laraclaw.connectors.slack.enabled' => true,
        'laraclaw.connectors.slack.bot_user_id' => 'UBOT',
    ]);
});

it('accepts a valid Slack channel message with bot mention', function () {
    $request = slackRequest(validPayload(['event' => ['type' => 'message', 'channel' => 'C123', 'text' => '<@UBOT> hello']]));

    Slack::validateEvent($request);
})->throwsNoExceptions();

it('accepts a valid DM from a registered account', function () {
    registerSlackAccount('U123');

    $request = slackRequest(validPayload(['event' => ['type' => 'message', 'channel' => 'D123', 'user' => 'U123', 'text' => 'hello']]));

    Slack::validateEvent($request);
})->throwsNoExceptions();

it('rejects when the connector is disabled', function () {
    config(['laraclaw.connectors.slack.enabled' => false]);

    expectValidationCode(
        slackRequest(validPayload()),
        'CHANNEL_DISABLED',
    );
});

it('rejects when bot user id is not configured', function () {
    config(['laraclaw.connectors.slack.bot_user_id' => null]);

    expectValidationCode(
        slackRequest(validPayload()),
        'CHANNEL_DISABLED',
    );
});

it('rejects when bot is not mentioned in a Slack channel message', function () {
    expectValidationCode(
        slackRequest(validPayload(['event' => ['type' => 'message', 'channel' => 'C123', 'text' => 'hello']])),
        'BOT_NOT_MENTIONED',
    );
});

it('rejects non-callback event types', function () {
    expectValidationCode(
        slackRequest(['type' => 'something_else', 'event' => ['type' => 'message', 'channel' => 'C123', 'text' => '<@UBOT> hello']]),
        'NOT_CALLBACK_EVENT',
    );
});

it('rejects non-message event types', function () {
    expectValidationCode(
        slackRequest(['type' => 'event_callback', 'event' => ['type' => 'reaction_added', 'channel' => 'C123', 'text' => '<@UBOT> hello']]),
        'NOT_MESSAGE',
    );
});

it('rejects bot messages', function () {
    expectValidationCode(
        slackRequest(validPayload(['event' => ['type' => 'message', 'channel' => 'C123', 'text' => '<@UBOT> hello', 'bot_id' => 'B123']])),
        'BOT_MESSAGE',
    );
});

it('rejects unsupported subtypes', function () {
    expectValidationCode(
        slackRequest(validPayload(['event' => ['type' => 'message', 'channel' => 'C123', 'text' => '<@UBOT> hello', 'subtype' => 'message_changed']])),
        'UNSUPPORTED_SUBTYPE',
    );
});

it('accepts file_share subtype', function () {
    $request = slackRequest(validPayload(['event' => ['type' => 'message', 'channel' => 'C123', 'text' => '<@UBOT> hello', 'subtype' => 'file_share']]));

    Slack::validateEvent($request);
})->throwsNoExceptions();

it('rejects when Slack channel is missing', function () {
    expectValidationCode(
        slackRequest(validPayload(['event' => ['type' => 'message', 'text' => '<@UBOT> hello']])),
        'NO_CHANNEL',
    );
});

it('rejects DM without user', function () {
    expectValidationCode(
        slackRequest(validPayload(['event' => ['type' => 'message', 'channel' => 'D123']])),
        'NO_USER',
    );
});

it('rejects DM from unregistered account', function () {
    expectValidationCode(
        slackRequest(validPayload(['event' => ['type' => 'message', 'channel' => 'D123', 'user' => 'U999', 'text' => 'hello']])),
        'UNREGISTERED_ACCOUNT',
    );
});

it('rejects empty text without files', function () {
    registerSlackAccount('U123');

    expectValidationCode(
        slackRequest(validPayload(['event' => ['type' => 'message', 'channel' => 'D123', 'user' => 'U123']])),
        'EMPTY_MESSAGE_WITHOUT_ATTACHMENTS',
    );
});

it('accepts empty text with files', function () {
    registerSlackAccount('U123');

    $request = slackRequest(validPayload(['event' => ['type' => 'message', 'channel' => 'D123', 'user' => 'U123', 'files' => [['name' => 'file.jpg']]]]));

    Slack::validateEvent($request);
})->throwsNoExceptions();
