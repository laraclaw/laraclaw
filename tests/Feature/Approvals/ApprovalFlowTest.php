<?php

use Laraclaw\Approvals\ApprovalFlow;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Thread;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

function approvalThread(array $pending = []): Thread
{
    return Thread::create([
        'connector' => ConnectorType::Telegram,
        'key' => '12345',
        'is_direct_message' => true,
        'pending_approvals' => $pending === [] ? null : $pending,
    ]);
}

function approvalMessage(?string $text): IncomingMessage
{
    return new IncomingMessage(
        text: $text,
        connector: ConnectorType::Telegram,
        key: '12345',
        isDirectMessage: true,
    );
}

function pendingPayload(): array
{
    return [['id' => 'call_abc', 'tool' => 'FileManager', 'arguments' => [], 'reason' => 'Delete a.txt?']];
}

// ── decisionsFrom ───────────────────────────────────────────────────────────

it('returns no decisions when the thread is not paused', function () {
    $decisions = app(ApprovalFlow::class)->decisionsFrom(approvalThread(), approvalMessage('Yes'));

    expect($decisions)->toBeNull();
});

it('approves every pending call on an affirmative reply', function (string $reply) {
    $decisions = app(ApprovalFlow::class)->decisionsFrom(approvalThread(pendingPayload()), approvalMessage($reply));

    expect($decisions->get('*')->isApproved())->toBeTrue();
})->with(['yes', 'Yes', 'YES', 'y', 'ok', 'sure', 'confirm', 'approve', 'go ahead']);

it('rejects with the user text when the reply is not affirmative', function () {
    $decisions = app(ApprovalFlow::class)->decisionsFrom(approvalThread(pendingPayload()), approvalMessage('actually delete b.txt'));

    expect($decisions->get('*')->isRejected())->toBeTrue()
        ->and($decisions->get('*')->result)->toBe('actually delete b.txt');
});

it('reads only the first line so quoted email replies still match', function () {
    $reply = "Yes\n\nOn Mar 6, the bot wrote:\n> Delete a.txt?";

    $decisions = app(ApprovalFlow::class)->decisionsFrom(approvalThread(pendingPayload()), approvalMessage($reply));

    expect($decisions->get('*')->isApproved())->toBeTrue();
});

it('falls back to a generic reason when the reply has no text', function () {
    $decisions = app(ApprovalFlow::class)->decisionsFrom(approvalThread(pendingPayload()), approvalMessage(null));

    expect($decisions->get('*')->isRejected())->toBeTrue()
        ->and($decisions->get('*')->result)->toBe('The user declined.');
});

// ── capture ─────────────────────────────────────────────────────────────────

it('records a pause on the thread and returns the question', function () {
    $thread = approvalThread();

    $question = app(ApprovalFlow::class)->capture($thread, AgentResponse::fakeWithPendingApprovals([
        new PendingApproval('call_abc', 'FileManager', ['path' => 'a.txt'], 'Delete `a.txt`?'),
    ]));

    expect($question)->toContain('Delete `a.txt`?')
        ->and($question)->toContain('Reply "yes" to approve')
        ->and($thread->fresh()->isAwaitingApproval())->toBeTrue()
        ->and($thread->fresh()->pending_approvals[0]['id'])->toBe('call_abc');
});

it('lists every pending call in one question', function () {
    $question = app(ApprovalFlow::class)->capture(approvalThread(), AgentResponse::fakeWithPendingApprovals([
        new PendingApproval('call_a', 'FileManager', [], 'Delete `a.txt`?'),
        new PendingApproval('call_b', 'EmailManager', [], 'Delete message 12?'),
    ]));

    expect($question)->toContain('Delete `a.txt`?')
        ->and($question)->toContain('Delete message 12?');
});

// ── recover ─────────────────────────────────────────────────────────────────

it('clears a stale pause and explains itself when the resume is rejected', function () {
    $thread = approvalThread(pendingPayload());

    $notice = app(ApprovalFlow::class)->recover(
        $thread,
        new ApprovalMismatchException('There are no tool calls pending approval.', collect()),
    );

    expect($notice)->toContain('no longer pending')
        ->and($thread->fresh()->isAwaitingApproval())->toBeFalse();
});

it('leaves other failures alone so the caller still handles them', function () {
    $thread = approvalThread(pendingPayload());

    $notice = app(ApprovalFlow::class)->recover($thread, new RuntimeException('boom'));

    expect($notice)->toBeNull()
        ->and($thread->fresh()->isAwaitingApproval())->toBeTrue();
});

it('clears a recorded pause once the turn resolves', function () {
    $thread = approvalThread(pendingPayload());

    $question = app(ApprovalFlow::class)->capture($thread, new AgentResponse('fake-invocation', 'All done.', new Usage, new Meta));

    expect($question)->toBeNull()
        ->and($thread->fresh()->isAwaitingApproval())->toBeFalse();
});
