<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laraclaw\Agents\ChatBotAgent;
use Laraclaw\Commands\Command;
use Laraclaw\Commands\CommandRegistry;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Http\Controllers\ApiController;
use Laraclaw\Http\Middleware\VerifyApiToken;
use Laraclaw\Models\Account;
use Laraclaw\Models\Thread;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

function apiToken(): string
{
    return 'test-api-token-for-laraclaw';
}

function apiHeaders(): array
{
    return ['Authorization' => 'Bearer ' . apiToken()];
}

function authenticatedUser(): \Illuminate\Foundation\Auth\User
{
    $user = test()->createUser();

    Account::updateOrCreate(
        ['user_id' => $user->getAuthIdentifier(), 'connector' => ConnectorType::Api],
        ['account' => hash('sha256', apiToken())],
    );

    return $user;
}

function mockAgent(string $text = 'Hello from agent', ?string $conversationId = 'conv-123'): void
{
    $response = new AgentResponse('inv-1', $text, new Usage, new Meta);
    $response->conversationId = $conversationId;

    $agent = Mockery::mock(ChatBotAgent::class);
    $agent->allows('prompt')->withAnyArgs()->andReturn($response);

    app()->bind(ChatBotAgent::class, fn () => $agent);
}

beforeEach(function () {
    Storage::fake(config('laraclaw.filesystem.attachments_disk', 'local'));

    Route::post('api/message', ApiController::class)
        ->middleware([VerifyApiToken::class]);
});

it('requires authentication', function () {
    $response = $this->postJson('/api/message', ['text' => 'hello']);

    $response->assertUnauthorized();
});

it('rejects an invalid token', function () {
    authenticatedUser();

    $response = $this->postJson('/api/message', ['text' => 'hello'], [
        'Authorization' => 'Bearer wrong-token',
    ]);

    $response->assertUnauthorized();
});

it('validates that text or attachments must be present', function () {
    authenticatedUser();

    $response = $this->postJson('/api/message', [], apiHeaders());

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('text');
});

it('accepts a text message and returns the agent response with a key', function () {
    authenticatedUser();
    mockAgent('Agent reply', 'conv-abc');

    $response = $this->postJson('/api/message', ['text' => 'Hello'], apiHeaders());

    $response->assertOk();
    $response->assertJson([
        'success' => true,
        'text' => 'Agent reply',
    ]);
    expect($response->json('key'))->toBeString()->not->toBeEmpty();
});

it('creates a new thread for each request without a key', function () {
    authenticatedUser();
    mockAgent();

    $first = $this->postJson('/api/message', ['text' => 'Hello'], apiHeaders());
    $second = $this->postJson('/api/message', ['text' => 'Hi again'], apiHeaders());

    expect($first->json('key'))->not->toBe($second->json('key'));
    expect(Thread::where('connector', ConnectorType::Api)->count())->toBe(2);
});

it('does not share threads between users that send the same client key', function () {
    authenticatedUser();

    $tokenB = 'second-user-token';
    $userB = test()->createUser();
    Account::updateOrCreate(
        ['user_id' => $userB->getAuthIdentifier(), 'connector' => ConnectorType::Api],
        ['account' => hash('sha256', $tokenB)],
    );

    mockAgent();

    $this->postJson('/api/message', ['text' => 'Hello', 'key' => 'shared'], apiHeaders());
    $this->postJson('/api/message', ['text' => 'Hello', 'key' => 'shared'], ['Authorization' => 'Bearer ' . $tokenB]);

    expect(Thread::where('connector', ConnectorType::Api)->count())->toBe(2);
});

it('generates a new key when an explicit null is sent', function () {
    authenticatedUser();
    mockAgent();

    $response = $this->postJson('/api/message', ['text' => 'Hello', 'key' => null], apiHeaders());

    $response->assertOk();
    expect($response->json('key'))->toBeString()->not->toBeEmpty();
});

it('preserves a literal "0" string as a valid key', function () {
    authenticatedUser();
    mockAgent();

    $response = $this->postJson('/api/message', ['text' => 'Hello', 'key' => '0'], apiHeaders());

    $response->assertOk();
    expect($response->json('key'))->toBe('0');
});

it('continues an existing thread when key is provided', function () {
    authenticatedUser();
    mockAgent();

    $first = $this->postJson('/api/message', ['text' => 'Hello'], apiHeaders());
    $key = $first->json('key');

    $second = $this->postJson('/api/message', [
        'text' => 'Follow up',
        'key' => $key,
    ], apiHeaders());

    $second->assertOk();
    expect($second->json('key'))->toBe($key);
    expect(Thread::where('connector', ConnectorType::Api)->count())->toBe(1);
});

it('stores the conversation id on the thread', function () {
    authenticatedUser();
    mockAgent('Reply', 'conv-xyz');

    $this->postJson('/api/message', ['text' => 'Hello'], apiHeaders());

    expect(Thread::where('connector', ConnectorType::Api)->where('conversation_id', 'conv-xyz')->exists())->toBeTrue();
});

it('handles commands and returns success without text', function () {
    authenticatedUser();

    $command = Mockery::mock(Command::class);
    $command->allows('trigger')->andReturn('!test');
    $command->expects('handle')->once()->with(
        Mockery::type(IncomingMessage::class),
        Mockery::type(Thread::class),
    )->andReturn(null);

    app(CommandRegistry::class)->register($command);

    $response = $this->postJson('/api/message', ['text' => '!test'], apiHeaders());

    $response->assertOk();
    $response->assertExactJson(['success' => true]);
});

it('does not call the agent when a command matches', function () {
    authenticatedUser();

    $command = Mockery::mock(Command::class);
    $command->allows('trigger')->andReturn('!reset');
    $command->allows('handle')->andReturn(null);

    app(CommandRegistry::class)->register($command);

    $agent = Mockery::mock(ChatBotAgent::class);
    $agent->shouldNotReceive('prompt');
    app()->bind(ChatBotAgent::class, fn () => $agent);

    $response = $this->postJson('/api/message', ['text' => '!reset'], apiHeaders());

    $response->assertOk();
});

it('accepts file attachments', function () {
    authenticatedUser();
    mockAgent();

    $file = UploadedFile::fake()->image('photo.jpg');

    $response = $this->postJson('/api/message', [
        'text' => 'See this image',
        'attachments' => [$file],
    ], apiHeaders());

    $response->assertOk();
    $response->assertJsonStructure([
        'success',
        'text',
        'key',
        'attachments',
    ]);
});

it('accepts attachments without text', function () {
    authenticatedUser();
    mockAgent();

    $file = UploadedFile::fake()->create('document.pdf', 100);

    $response = $this->postJson('/api/message', [
        'attachments' => [$file],
    ], apiHeaders());

    $response->assertOk();
});

it('saves uploaded files to inbound storage', function () {
    authenticatedUser();
    mockAgent();

    $file = UploadedFile::fake()->image('photo.jpg');

    $this->postJson('/api/message', [
        'text' => 'Check this',
        'attachments' => [$file],
    ], apiHeaders());

    $disk = config('laraclaw.filesystem.attachments_disk', 'local');
    $inboundPath = config('laraclaw.filesystem.incoming_attachments_path', 'inbound');

    $files = Storage::disk($disk)->allFiles($inboundPath);
    expect($files)->toHaveCount(1);
    expect($files[0])->toContain('photo.jpg');
});

it('sanitizes uploaded filenames with path traversal attempts', function () {
    authenticatedUser();
    mockAgent();

    $file = UploadedFile::fake()->create('../../etc/passwd', 10);

    $this->postJson('/api/message', [
        'text' => 'malicious',
        'attachments' => [$file],
    ], apiHeaders());

    $disk = config('laraclaw.filesystem.attachments_disk', 'local');
    $inboundPath = config('laraclaw.filesystem.incoming_attachments_path', 'inbound');

    $files = Storage::disk($disk)->allFiles($inboundPath);
    expect($files)->toHaveCount(1);

    // The file should be stored with just the basename, not the traversal path
    expect($files[0])->not->toContain('..');
    expect($files[0])->toStartWith($inboundPath . '/');
});

it('returns outbound attachments in the response', function () {
    authenticatedUser();

    $response = new AgentResponse('inv-1', 'Here is your file', new Usage, new Meta);
    $response->conversationId = 'conv-1';

    $agent = Mockery::mock(ChatBotAgent::class);
    $agent->allows('prompt')->withAnyArgs()->andReturnUsing(
        function () use ($response): AgentResponse {
            // Simulate the agent writing an outbound file during prompt.
            // Find the UUID from the inbound files that were already saved.
            $disk = config('laraclaw.filesystem.attachments_disk', 'local');
            $inboundPath = config('laraclaw.filesystem.incoming_attachments_path', 'inbound');
            $inboundFiles = Storage::disk($disk)->allFiles($inboundPath);

            if (! empty($inboundFiles)) {
                $parts = explode('/', $inboundFiles[0]);
                $uuid = $parts[1] ?? null;

                if ($uuid) {
                    $outboundPath = config('laraclaw.filesystem.outgoing_attachments_path', 'outbound');
                    Storage::disk($disk)->put("{$outboundPath}/{$uuid}/result.txt", 'output content');
                }
            }

            return $response;
        }
    );

    app()->bind(ChatBotAgent::class, fn () => $agent);

    $file = UploadedFile::fake()->create('input.txt', 10);

    $result = $this->postJson('/api/message', [
        'text' => 'Process this',
        'attachments' => [$file],
    ], apiHeaders());

    $result->assertOk();
    $result->assertJsonCount(1, 'attachments');
    $result->assertJsonPath('attachments.0.filename', 'result.txt');
});

it('rejects an upload larger than the configured size budget', function () {
    authenticatedUser();
    mockAgent();
    config(['laraclaw.filesystem.max_attachment_kilobytes' => 100]);

    $file = UploadedFile::fake()->create('huge.bin', 500);

    $response = $this->postJson('/api/message', [
        'text' => 'Too big',
        'attachments' => [$file],
    ], apiHeaders());

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('attachments.0');
});

it('accepts an upload inside the configured size budget', function () {
    authenticatedUser();
    mockAgent();
    config(['laraclaw.filesystem.max_attachment_kilobytes' => 100]);

    $file = UploadedFile::fake()->create('small.bin', 50);

    $response = $this->postJson('/api/message', [
        'text' => 'Just right',
        'attachments' => [$file],
    ], apiHeaders());

    $response->assertOk();
});

it('returns a validation error rather than a database error for an oversized key', function () {
    authenticatedUser();
    mockAgent();

    $response = $this->postJson('/api/message', [
        'text' => 'Hello',
        'key' => str_repeat('k', 300),
    ], apiHeaders());

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('key');
    expect(Thread::where('connector', ConnectorType::Api)->count())->toBe(0);
});

it('leaves room for the user id prefix when bounding the key', function () {
    $user = authenticatedUser();
    mockAgent();

    // A key of exactly the column length still overflows once the prefix is added.
    $this->postJson('/api/message', ['text' => 'Hello', 'key' => str_repeat('k', 255)], apiHeaders())
        ->assertUnprocessable();

    $longestAllowed = str_repeat('k', 255 - strlen((string) $user->getAuthIdentifier()) - 1);

    $this->postJson('/api/message', ['text' => 'Hello', 'key' => $longestAllowed], apiHeaders())
        ->assertOk();
});

it('rejects prompt text beyond the maximum length', function () {
    authenticatedUser();
    mockAgent();

    $response = $this->postJson('/api/message', [
        'text' => str_repeat('a', 100001),
    ], apiHeaders());

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('text');
});
