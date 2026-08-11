<?php

namespace Laraclaw\Approvals;

use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Models\Thread;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

/**
 * Translates between the SDK tool approval pause and the plain text replies a chat user sends.
 *
 * When a gated tool call pauses the agent, the run is suspended in the laravel/ai
 * conversation history and can be resumed later with a decision for each pending
 * call. Chat users do not send tool call IDs, so this records the pause on the
 * thread and reads the next inbound message as the answer to it.
 */
class ApprovalFlow
{
    /**
     * Replies that approve every pending tool call.
     */
    private const array AFFIRMATIVE = ['yes', 'y', 'ok', 'okay', 'yep', 'yeah', 'sure', 'confirm', 'approve', 'do it', 'go ahead'];

    /**
     * Build the decisions to resume with, or null when the thread is not paused.
     *
     * Anything other than a clear yes is treated as a rejection carrying the user's
     * own words, so the model keeps the turn and can respond to what they actually
     * asked for instead of stopping dead.
     */
    public function decisionsFrom(Thread $thread, IncomingMessage $message): ?Decisions
    {
        if (! $thread->isAwaitingApproval()) {
            return null;
        }

        $reply = $this->firstLine($message->text);

        if (in_array(mb_strtolower($reply), self::AFFIRMATIVE, true)) {
            return Decision::approveAll();
        }

        return Decisions::from(['*' => Decision::reject($reply === '' ? 'The user declined.' : $reply)]);
    }

    /**
     * Record any new pause on the thread and return the question to put to the user.
     *
     * Returns null when the run finished normally, in which case the caller should
     * send the agent's own reply as usual.
     */
    public function capture(Thread $thread, AgentResponse $response): ?string
    {
        if (! $response->hasPendingApprovals()) {
            // The turn resolved, so any pause we were tracking is spent.
            if ($thread->isAwaitingApproval()) {
                $thread->update(['pending_approvals' => null]);
            }

            return null;
        }

        $pending = $response->pendingApprovals;

        $thread->update([
            'pending_approvals' => $pending
                ->map(fn (PendingApproval $approval): array => $approval->toArray())
                ->all(),
        ]);

        return $this->question($pending->all());
    }

    /**
     * Forget a recorded pause, used when a resume is rejected as stale.
     */
    public function forget(Thread $thread): void
    {
        $thread->update(['pending_approvals' => null]);
    }

    /**
     * Recover from a resume the SDK rejected, returning what to tell the user.
     *
     * Our record of the pause can fall out of step with the conversation history,
     * for instance when a call was already resolved elsewhere. Clearing it here
     * matters: otherwise the thread stays stuck and every later message is read
     * as an answer to a question that is no longer open.
     *
     * Returns null for any other failure so the caller handles it as usual.
     */
    public function recover(Thread $thread, Throwable $e): ?string
    {
        if (! $e instanceof ApprovalMismatchException) {
            return null;
        }

        $this->forget($thread);

        return 'That approval is no longer pending, so I left everything as it was. Ask me again if you still want it done.';
    }

    /**
     * Render the pending approvals as a single message asking the user to approve.
     *
     * @param  PendingApproval[]  $pending
     */
    private function question(array $pending): string
    {
        $reasons = collect($pending)
            ->map(fn (PendingApproval $approval): string => $approval->reason ?? "Run {$approval->tool}?")
            ->filter()
            ->implode("\n");

        return "⚠️ {$reasons}\n\nReply \"yes\" to approve, or tell me what to do instead.";
    }

    /**
     * Return the first non-empty line of the reply.
     *
     * Email replies quote the original message below the answer, so only the
     * first line is a reliable read of what the user actually said.
     */
    private function firstLine(?string $text): string
    {
        return trim((string) strtok(trim((string) $text), "\n"));
    }
}
