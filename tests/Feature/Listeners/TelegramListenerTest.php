<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laraclaw\Commands\Command;
use Laraclaw\Commands\CommandRegistry;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Events\TelegramMessageReceived;
use Laraclaw\Jobs\RunAgentTurn;
use Laraclaw\Listeners\TelegramListener;
use Laraclaw\Models\Account;
use Laraclaw\Models\Thread;
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

beforeEach(function () {
    Log::spy();
    Bus::fake();
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

    app(TelegramListener::class)(makeTelegramEvent(chatId: -100123));

    Log::shouldNotHaveReceived('debug');
});

it('queues the turn with the message it has to answer', function () {
    registerTelegramAccount(12345);

    app(TelegramListener::class)(makeTelegramEvent(chatId: 12345, text: 'Hello bot'));

    Bus::assertDispatched(RunAgentTurn::class, fn (RunAgentTurn $job): bool => $job->message->text === 'Hello bot'
        && $job->thread->key === '12345');
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

    Bus::assertNotDispatched(RunAgentTurn::class);
    Log::shouldNotHaveReceived('error');
});

it('queues the agent when no command matches', function () {
    registerTelegramAccount(12345);

    app(TelegramListener::class)(makeTelegramEvent(chatId: 12345));

    Bus::assertDispatched(RunAgentTurn::class);
});

it('creates a thread record for incoming messages', function () {
    registerTelegramAccount(12345);

    app(TelegramListener::class)(makeTelegramEvent(chatId: 12345));

    expect(Thread::where('connector', ConnectorType::Telegram)->where('key', '12345')->exists())->toBeTrue();
});

it('accepts messages with a caption but no text', function () {
    registerTelegramAccount(12345);

    $event = makeTelegramEvent(chatId: 12345, text: null, extra: ['caption' => 'Photo caption']);

    app(TelegramListener::class)($event);

    Log::shouldNotHaveReceived('debug');
});

it('accepts messages with media but no text or caption', function () {
    registerTelegramAccount(12345);

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
