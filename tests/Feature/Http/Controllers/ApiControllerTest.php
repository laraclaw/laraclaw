<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use LaraClaw\Agents\ChatBotAgent;
use LaraClaw\Commands\Command;
use LaraClaw\Commands\CommandRegistry;
use LaraClaw\DTOs\IncomingMessage;
use LaraClaw\Enums\ChannelType;
use LaraClaw\Http\Controllers\ApiController;
use LaraClaw\Models\Thread;
use LaraClaw\Models\UserAccount;
use LaraClaw\Services\Attachments;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

function authenticatedUser(): \Illuminate\Foundation\Auth\User
{
    return test()->createUser();
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

    // Register the API route with the web auth guard since Sanctum is not
    // available in the package test environment. The real route uses auth:sanctum.
    Route::post('api/message', ApiController::class)
        ->middleware(['auth']);
});

it('requires authentication', function () {
    $response = $this->postJson('/api/message', ['text' => 'hello']);

    $response->assertUnauthorized();
});

it('validates that text or attachments must be present', function () {
    $user = authenticatedUser();

    $response = $this->actingAs($user)->postJson('/api/message', []);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('text');
});

it('accepts a text message and returns the agent response with a key', function () {
    $user = authenticatedUser();
    mockAgent('Agent reply', 'conv-abc');

    $response = $this->actingAs($user)->postJson('/api/message', ['text' => 'Hello']);

    $response->assertOk();
    $response->assertJson([
        'success' => true,
        'text' => 'Agent reply',
    ]);
    expect($response->json('key'))->toBeString()->not->toBeEmpty();
});

it('creates a user account record for the authenticated user', function () {
    $user = authenticatedUser();
    mockAgent();

    $this->actingAs($user)->postJson('/api/message', ['text' => 'Hi']);

    expect(UserAccount::where('channel', ChannelType::Api)
        ->where('account', $user->getAuthIdentifier())
        ->exists()
    )->toBeTrue();
});

it('does not duplicate the user account on subsequent requests', function () {
    $user = authenticatedUser();
    mockAgent();

    $this->actingAs($user)->postJson('/api/message', ['text' => 'First']);
    $this->actingAs($user)->postJson('/api/message', ['text' => 'Second']);

    expect(UserAccount::where('channel', ChannelType::Api)
        ->where('account', $user->getAuthIdentifier())
        ->count()
    )->toBe(1);
});

it('creates a new thread for each request without a key', function () {
    $user = authenticatedUser();
    mockAgent();

    $first = $this->actingAs($user)->postJson('/api/message', ['text' => 'Hello']);
    $second = $this->actingAs($user)->postJson('/api/message', ['text' => 'Hi again']);

    expect($first->json('key'))->not->toBe($second->json('key'));
    expect(Thread::where('channel', ChannelType::Api)->count())->toBe(2);
});

it('continues an existing thread when key is provided', function () {
    $user = authenticatedUser();
    mockAgent();

    $first = $this->actingAs($user)->postJson('/api/message', ['text' => 'Hello']);
    $key = $first->json('key');

    $second = $this->actingAs($user)->postJson('/api/message', [
        'text' => 'Follow up',
        'key' => $key,
    ]);

    $second->assertOk();
    expect($second->json('key'))->toBe($key);
    expect(Thread::where('channel', ChannelType::Api)->count())->toBe(1);
});

it('stores the conversation id on the thread', function () {
    $user = authenticatedUser();
    mockAgent('Reply', 'conv-xyz');

    $response = $this->actingAs($user)->postJson('/api/message', ['text' => 'Hello']);
    $key = $response->json('key');

    $thread = Thread::where('channel', ChannelType::Api)->where('key', $key)->first();
    expect($thread->conversation_id)->toBe('conv-xyz');
});

it('handles commands and returns success without text', function () {
    $user = authenticatedUser();

    $command = Mockery::mock(Command::class);
    $command->allows('trigger')->andReturn('!test');
    $command->expects('handle')->once()->with(
        Mockery::type(IncomingMessage::class),
        Mockery::type(Thread::class),
    )->andReturn(null);

    app(CommandRegistry::class)->register($command);

    $response = $this->actingAs($user)->postJson('/api/message', ['text' => '!test']);

    $response->assertOk();
    $response->assertExactJson(['success' => true]);
});

it('does not call the agent when a command matches', function () {
    $user = authenticatedUser();

    $command = Mockery::mock(Command::class);
    $command->allows('trigger')->andReturn('!reset');
    $command->allows('handle')->andReturn(null);

    app(CommandRegistry::class)->register($command);

    $agent = Mockery::mock(ChatBotAgent::class);
    $agent->shouldNotReceive('prompt');
    app()->bind(ChatBotAgent::class, fn () => $agent);

    $response = $this->actingAs($user)->postJson('/api/message', ['text' => '!reset']);

    $response->assertOk();
});

it('accepts file attachments', function () {
    $user = authenticatedUser();
    mockAgent();

    $file = UploadedFile::fake()->image('photo.jpg');

    $response = $this->actingAs($user)->postJson('/api/message', [
        'text' => 'See this image',
        'attachments' => [$file],
    ]);

    $response->assertOk();
    $response->assertJsonStructure([
        'success',
        'text',
        'key',
        'attachments',
    ]);
});

it('accepts attachments without text', function () {
    $user = authenticatedUser();
    mockAgent();

    $file = UploadedFile::fake()->create('document.pdf', 100);

    $response = $this->actingAs($user)->postJson('/api/message', [
        'attachments' => [$file],
    ]);

    $response->assertOk();
});

it('saves uploaded files to inbound storage', function () {
    $user = authenticatedUser();
    mockAgent();

    $file = UploadedFile::fake()->image('photo.jpg');

    $this->actingAs($user)->postJson('/api/message', [
        'text' => 'Check this',
        'attachments' => [$file],
    ]);

    $disk = config('laraclaw.filesystem.attachments_disk', 'local');
    $inboundPath = config('laraclaw.filesystem.incoming_attachments_path', 'inbound');

    $files = Storage::disk($disk)->allFiles($inboundPath);
    expect($files)->toHaveCount(1);
    expect($files[0])->toContain('photo.jpg');
});

it('sanitizes uploaded filenames with path traversal attempts', function () {
    $user = authenticatedUser();
    mockAgent();

    $file = UploadedFile::fake()->create('../../etc/passwd', 10);

    $this->actingAs($user)->postJson('/api/message', [
        'text' => 'malicious',
        'attachments' => [$file],
    ]);

    $disk = config('laraclaw.filesystem.attachments_disk', 'local');
    $inboundPath = config('laraclaw.filesystem.incoming_attachments_path', 'inbound');

    $files = Storage::disk($disk)->allFiles($inboundPath);
    expect($files)->toHaveCount(1);

    // The file should be stored with just the basename, not the traversal path
    expect($files[0])->not->toContain('..');
    expect($files[0])->toStartWith($inboundPath . '/');
});

it('returns outbound attachments in the response', function () {
    $user = authenticatedUser();

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

    $result = $this->actingAs($user)->postJson('/api/message', [
        'text' => 'Process this',
        'attachments' => [$file],
    ]);

    $result->assertOk();
    $result->assertJsonCount(1, 'attachments');
    $result->assertJsonPath('attachments.0.filename', 'result.txt');
});
