<?php

use Illuminate\Pipeline\Pipeline;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laraclaw\Agents\ChatBotAgent;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Jobs\RunAgentTurn;
use Laraclaw\Models\Account;
use Laraclaw\Models\Thread;
use Laraclaw\Tests\Fixtures\Fake;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Responses\AgentResponse;

/**
 * Track everything the stand in agent was asked and every conversation it opened.
 */
function turnLog(): stdClass
{
    return test()->log;
}

/**
 * Bind an agent that behaves like the SDK does around conversations.
 *
 * It binds whatever conversation the thread holds at the moment it is built, and
 * opens a new one only when the thread has none. That is the behavior the race
 * exploits, so the test is only meaningful with it in place.
 */
function bindConversationalAgent(): void
{
    app()->bind(ChatBotAgent::class, function ($app, array $parameters) {
        $log = turnLog();
        $bound = $parameters['thread']->conversation_id;

        $agent = Mockery::mock(ChatBotAgent::class);

        $agent->allows('prompt')->andReturnUsing(function (...$arguments) use ($log, $bound) {
            $log->prompts[] = $arguments[0] instanceof Decisions ? $arguments[0] : (string) $arguments[0];

            $conversationId = $bound ?? 'conv-' . (count($log->conversations) + 1);

            if (! in_array($conversationId, $log->conversations, true)) {
                $log->conversations[] = $conversationId;
            }

            $response = Mockery::mock(AgentResponse::class);
            $response->conversationId = $conversationId;
            $response->text = 'Answered.';
            $response->allows('hasPendingApprovals')->andReturnFalse();

            return $response;
        });

        return $agent;
    });
}

function turnMessage(string $text): IncomingMessage
{
    return new IncomingMessage(
        text: $text,
        connector: ConnectorType::Telegram,
        key: '12345',
        isDirectMessage: true,
    );
}

function turnThread(): Thread
{
    return Thread::forMessage(turnMessage('anything'));
}

beforeEach(function () {
    Log::spy();

    $this->log = (object) ['prompts' => [], 'conversations' => []];

    $user = $this->createUser();
    config(['laraclaw.auth.admin_user_id' => $user->getAuthIdentifier()]);

    Account::create([
        'user_id' => $user->getAuthIdentifier(),
        'connector' => ConnectorType::Telegram,
        'account' => '12345',
    ]);

    $this->connector = new Fake;

    bindConversationalAgent();
});

it('keeps two messages sent at once in a single conversation', function () {
    $thread = turnThread();

    // Both webhooks landed before either turn ran, so both jobs were queued
    // against a thread that had no conversation yet. This is the race.
    $first = new RunAgentTurn($thread, turnMessage('first'), $this->connector);
    $second = new RunAgentTurn($thread, turnMessage('second'), $this->connector);

    app()->call([$first, 'handle']);
    app()->call([$second, 'handle']);

    // One conversation, both turns in it, and the saved id is the one they share.
    expect(turnLog()->conversations)->toBe(['conv-1'])
        ->and(turnLog()->prompts)->toBe(['first', 'second'])
        ->and($thread->fresh()->conversation_id)->toBe('conv-1');
});

it('answers both messages rather than dropping the second', function () {
    $thread = turnThread();

    app()->call([new RunAgentTurn($thread, turnMessage('first'), $this->connector), 'handle']);
    app()->call([new RunAgentTurn($thread, turnMessage('second'), $this->connector), 'handle']);

    expect($this->connector->sent)->toBe(['Answered.', 'Answered.']);
});

it('releases a turn back to the queue while another turn holds the thread', function () {
    $thread = turnThread();

    // The turn ahead of this one is still running.
    Cache::lock($thread->lockKey(), 60)->get();

    $job = new RunAgentTurn($thread, turnMessage('second'), $this->connector);
    $job->withFakeQueueInteractions();

    app(Pipeline::class)
        ->send($job)
        ->through($job->middleware())
        ->then(fn () => throw new RuntimeException('The second turn ran while the first held the lock.'));

    // Released, not discarded, so the message is still answered once the lock frees.
    $job->assertReleased(delay: 5);

    expect(turnLog()->prompts)->toBe([]);
});

it('runs the turn once the thread lock is free', function () {
    $thread = turnThread();

    $job = new RunAgentTurn($thread, turnMessage('hello'), $this->connector);
    $job->withFakeQueueInteractions();

    app(Pipeline::class)
        ->send($job)
        ->through($job->middleware())
        ->then(fn (RunAgentTurn $job) => app()->call([$job, 'handle']));

    $job->assertNotReleased();

    expect($this->connector->sent)->toBe(['Answered.']);
});

it('locks the thread under the name the synchronous API path uses', function () {
    $thread = turnThread();
    $middleware = (new RunAgentTurn($thread, turnMessage('hello')))->middleware()[0];

    expect($middleware)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware->getLockKey(new stdClass))->toBe($thread->lockKey());
});

it('takes the same lock as a routine running on the same thread', function () {
    $thread = turnThread();

    expect(Thread::lockKeyFor(ConnectorType::Telegram, '12345'))->toBe($thread->lockKey());
});

it('reads the pending approval when the turn runs, not when it is queued', function () {
    $thread = turnThread();

    $job = new RunAgentTurn($thread, turnMessage('yes'), $this->connector);

    // The turn ahead of this one paused on a gated tool call after this job was queued.
    $thread->update([
        'conversation_id' => (string) Str::uuid(),
        'pending_approvals' => [[
            'id' => 'call_abc',
            'tool' => 'FileManager',
            'arguments' => [],
            'reason' => 'Delete a.txt?',
        ]],
    ]);

    app()->call([$job, 'handle']);

    // Queued before the pause existed, yet still read as the answer to it.
    expect(turnLog()->prompts[0])->toBeInstanceOf(Decisions::class)
        ->and(turnLog()->prompts[0]->get('*')->isApproved())->toBeTrue();
});

it('clears a stale pause and tells the user when the turn fails', function () {
    $thread = turnThread();
    $thread->update(['pending_approvals' => [['id' => 'call_abc', 'tool' => 'FileManager', 'arguments' => []]]]);

    (new RunAgentTurn($thread, turnMessage('yes'), $this->connector))
        ->failed(new ApprovalMismatchException('No such pending approval.', collect()));

    expect($thread->fresh()->pending_approvals)->toBeNull()
        ->and($this->connector->sent[0])->toContain('no longer pending');
});

it('logs any other failure against the thread', function () {
    $thread = turnThread();

    (new RunAgentTurn($thread, turnMessage('hello'), $this->connector))
        ->failed(new RuntimeException('provider exploded'));

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['error'] === 'provider exploded'
            && $context['key'] === '12345');
});
