<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use LaraClaw\Agents\ChatBotAgent;
use LaraClaw\Commands\Command;
use LaraClaw\Commands\CommandRegistry;
use LaraClaw\DTOs\IncomingMessage;
use LaraClaw\Enums\ConnectorType;
use LaraClaw\Events\TelegramMessageReceived;
use LaraClaw\Listeners\TelegramListener;
use LaraClaw\Models\Thread;
use LaraClaw\Models\Account;
use Laravel\Ai\Responses\QueuedAgentResponse;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Chat;
use Telegram\Bot\Objects\File;
use Telegram\Bot\Objects\Message as TelegramMessage;

function makeTelegramMessage(int $chatId = 12345, ?string $text = 'Hello bot', array $extra = []): TelegramMessage
{
    $chat = new Chat(['id' => $chatId, 'type' => $chatId > 0 ? 'private' : 'group']);

    $message = Mockery::mock(TelegramMessage::class)->makePartial();
    $message->allows('getChat')->andReturn($chat);
    $message->allows('getText')->andReturn($text);
    $message->allows('getCaption')->andReturn($extra['caption'] ?? null);
    $message->allows('getPhoto')->andReturn($extra['photo'] ?? null);
    $message->allows('getAudio')->andReturn($extra['audio'] ?? null);
    $message->allows('getVoice')->andReturn($extra['voice'] ?? null);
    $message->allows('getVideo')->andReturn($extra['video'] ?? null);
    $message->allows('getDocument')->andReturn($extra['document'] ?? null);

    return $message;
}

function makeTelegramEvent(int $chatId = 12345, ?string $text = 'Hello bot', array $extra = []): TelegramMessageReceived
{
    $bot = Mockery::mock(Api::class);
    $message = makeTelegramMessage($chatId, $text, $extra);

    return new TelegramMessageReceived($message, $bot);
}

function registerTelegramAccount(int $chatId = 12345): void
{
    $user = test()->createUser();
    config(['laraclaw.auth.admin_user_id' => $user->getAuthIdentifier()]);

    Account::create([
        'user_id' => $user->getAuthIdentifier(),
        'connector' => ConnectorType::Telegram,
        'account' => (string) $chatId,
    ]);
}

function mockAgentQueue(): Mockery\MockInterface
{
    $queued = Mockery::mock(QueuedAgentResponse::class);
    $queued->allows('then')->andReturnSelf();
    $queued->allows('catch')->andReturnSelf();

    $agent = Mockery::mock(ChatBotAgent::class);
    $agent->allows('queue')->withAnyArgs()->andReturn($queued);

    app()->bind(ChatBotAgent::class, fn () => $agent);

    return $agent;
}

beforeEach(function () {
    Log::spy();
    Redis::spy();
    config(['laraclaw.connectors.telegram.enabled' => true]);
});

it('skips the message when the telegram connector is disabled', function () {
    config(['laraclaw.connectors.telegram.enabled' => false]);

    app(TelegramListener::class)(makeTelegramEvent());

    Log::shouldHaveReceived('debug')
        ->once()
        ->withArgs(fn ($msg, $ctx) => str_contains($ctx['code'], 'CHANNEL_DISABLED'));
});

it('skips DMs from unregistered accounts', function () {
    app(TelegramListener::class)(makeTelegramEvent(chatId: 99999));

    Log::shouldHaveReceived('debug')
        ->once()
        ->withArgs(fn ($msg, $ctx) => str_contains($ctx['code'], 'UNREGISTERED_ACCOUNT'));
});

it('skips empty messages with no text, caption, or media', function () {
    registerTelegramAccount(12345);

    app(TelegramListener::class)(makeTelegramEvent(chatId: 12345, text: null));

    Log::shouldHaveReceived('debug')
        ->once()
        ->withArgs(fn ($msg, $ctx) => str_contains($ctx['code'], 'EMPTY_MESSAGE'));
});

it('allows group messages without a registered account', function () {
    $user = test()->createUser();
    config(['laraclaw.auth.admin_user_id' => $user->getAuthIdentifier()]);
    mockAgentQueue();

    app(TelegramListener::class)(makeTelegramEvent(chatId: -100123));

    Log::shouldNotHaveReceived('debug');
});

it('stops processing when the message resolves a pending confirmation', function () {
    registerTelegramAccount(12345);
    mockAgentQueue();

    // Simulate a pending confirmation in Redis
    Redis::allows('exists')->andReturn(true);
    Redis::allows('rpush')->andReturn(1);

    app(TelegramListener::class)(makeTelegramEvent(chatId: 12345, text: 'Yes'));

    // Agent should not have been queued, so no error logs either.
    // The listener returns early after resolvePendingConfirmation().
    Log::shouldNotHaveReceived('error');
});

it('executes a matched command and does not queue the agent', function () {
    registerTelegramAccount(12345);

    $command = Mockery::mock(Command::class);
    $command->allows('trigger')->andReturn('!test');
    $command->expects('handle')->once()->with(
        Mockery::type(IncomingMessage::class),
        Mockery::type(Thread::class),
    )->andReturn(null);

    $registry = app(CommandRegistry::class);
    $registry->register($command);

    app(TelegramListener::class)(makeTelegramEvent(chatId: 12345, text: '!test'));

    Log::shouldNotHaveReceived('error');
});

it('queues the agent when no command matches', function () {
    registerTelegramAccount(12345);

    $queued = Mockery::mock(QueuedAgentResponse::class);
    $queued->expects('then')->once()->andReturnSelf();
    $queued->expects('catch')->once()->andReturnSelf();

    $agent = Mockery::mock(ChatBotAgent::class);
    $agent->expects('queue')->once()->andReturn($queued);

    app()->bind(ChatBotAgent::class, fn () => $agent);

    app(TelegramListener::class)(makeTelegramEvent(chatId: 12345));
});

it('creates a thread record for incoming messages', function () {
    registerTelegramAccount(12345);
    mockAgentQueue();

    app(TelegramListener::class)(makeTelegramEvent(chatId: 12345));

    expect(Thread::where('connector', ConnectorType::Telegram)->where('key', '12345')->exists())->toBeTrue();
});

it('accepts messages with a caption but no text', function () {
    registerTelegramAccount(12345);
    mockAgentQueue();

    $event = makeTelegramEvent(chatId: 12345, text: null, extra: ['caption' => 'Photo caption']);

    app(TelegramListener::class)($event);

    Log::shouldNotHaveReceived('debug');
});

it('accepts messages with media but no text or caption', function () {
    registerTelegramAccount(12345);
    mockAgentQueue();

    $audio = Mockery::mock();
    $audio->allows('getFileId')->andReturn('audio-file-id');
    $audio->allows('getMimeType')->andReturn('audio/mpeg');
    $audio->allows('getFileName')->andReturn('song.mp3');

    $fileMeta = new File([
        'file_id' => 'audio-file-id',
        'file_path' => 'audio/song.mp3',
    ]);

    $bot = Mockery::mock(Api::class);
    $bot->allows('getFile')->andReturn($fileMeta);
    $bot->allows('sendChatAction')->andReturn(true);
    $bot->allows('getAccessToken')->andReturn('fake-token');

    // Fake the HTTP download so no real network call is made
    Http::fake([
        'api.telegram.org/*' => Http::response('audio-content', 200),
    ]);

    $message = makeTelegramMessage(chatId: 12345, text: null, extra: ['audio' => $audio]);
    $event = new TelegramMessageReceived($message, $bot);

    app(TelegramListener::class)($event);

    Log::shouldNotHaveReceived('debug');
});
