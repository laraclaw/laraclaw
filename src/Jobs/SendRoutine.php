<?php

namespace Laraclaw\Jobs;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Laraclaw\Agents\ChatBotAgent;
use Laraclaw\Approvals\ApprovalFlow;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Routine;
use Laraclaw\Models\Thread;
use Laraclaw\Services\Attachments;
use Laravel\Ai\Approvals\Decision;
use Throwable;

/**
 * Queued job that sends a routine prompt to the agent and delivers its response.
 */
class SendRoutine implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * Expire the dedupe lock after an hour so a job that vanishes before it runs
     * does not leave its key behind for good.
     *
     * The lock only stops a duplicate going onto the queue. It has no say over
     * last_run_at, which is what actually keeps the routine from running twice,
     * and is released by failed() rather than by this timeout.
     */
    public int $uniqueFor = 3600;

    /**
     * Bind the routine row that this job will fire on dispatch, along with the
     * last_run_at value it had before the dispatching command claimed it. That
     * earlier value is what we put back if the job fails.
     */
    public function __construct(
        private Routine $routine,
        private ?CarbonInterface $previousRunAt = null,
    ) {}

    /**
     * Keep one queued job for each routine row.
     */
    public function uniqueId(): string
    {
        return (string) $this->routine->getKey();
    }

    /**
     * Run the routine prompt through the agent and deliver the response via the connector.
     *
     * DMs and Telegram groups continue the user's existing conversation so the agent
     * has the context of prior turns. A Slack channel gets a fresh top level post and
     * a fresh conversation each time, so nothing carries over between runs.
     */
    public function handle(): void
    {
        $message = $this->message();
        $thread = $this->thread($message);

        $agent = resolve(ChatBotAgent::class, ['message' => $message, 'thread' => $thread]);
        $response = $agent->prompt(...$message->toAgentInput());

        // A routine fires with nobody waiting on it, so a gated tool call cannot be
        // answered inline. Slack channels throw their conversation away after each run
        // and so could never be resumed; reject there and let the agent report back.
        if ($response->hasPendingApprovals() && $this->isSlackChannel()) {
            $response = $agent
                ->continue($response->conversationId, as: $thread->user())
                ->prompt(Decision::rejectAll('Automated routine runs cannot approve tool calls.'));
        }

        if (! $this->isSlackChannel()) {
            $thread->update(['conversation_id' => $response->conversationId]);
        }

        // Everywhere else the thread is resumable, so the pause becomes a question the
        // user can answer with their next message like any other approval.
        $question = $this->isSlackChannel() ? null : resolve(ApprovalFlow::class)->capture($thread, $response);

        $thread->connector()->reply(
            thread: $thread,
            text: $question ?? $response->text,
            attachments: resolve(Attachments::class)->outbound($message->uuid)->getAll(),
        );

        // The dispatching command already stamped last_run_at to claim the row.
        // Writing it again keeps the column honest about when the run finished.
        $this->routine->update(['last_run_at' => now()]);
    }

    /**
     * Log the failure and hand the routine back so a later pass can run it again.
     *
     * Restoring the previous last_run_at releases the claim the dispatching
     * command took. Without this a routine that failed would look like it had
     * just run and would sit idle until its next cron occurrence. The write goes
     * through a query so it lands even when the model we hold still has the
     * value it was dispatched with.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('SendRoutine failed', [
            'routine_id' => $this->routine->id,
            'connector' => $this->routine->connector->value,
            'key' => $this->routine->key,
            'error' => $exception->getMessage(),
        ]);

        Routine::whereKey($this->routine->getKey())->update(['last_run_at' => $this->previousRunAt]);
    }

    /**
     * Build the message the agent is prompted with, addressed to where the routine posts.
     *
     * Slack channel keys are stored as "channelId:threadTs". The threadTs is
     * dropped so the connector posts a new top level message each time.
     */
    private function message(): IncomingMessage
    {
        return new IncomingMessage(
            text: $this->framedPrompt(),
            connector: $this->routine->connector,
            key: $this->isSlackChannel() ? explode(':', $this->routine->key, 2)[0] : $this->routine->key,
            isDirectMessage: $this->routine->connector->isDirectMessage($this->routine->key),
        );
    }

    /**
     * Find or create the thread the routine posts to.
     *
     * A Slack channel forgets its conversation in memory only, so the agent starts
     * fresh without the reset being written back.
     */
    private function thread(IncomingMessage $message): Thread
    {
        $thread = Thread::forMessage($message);

        if ($this->isSlackChannel()) {
            $thread->conversation_id = null;
        }

        return $thread;
    }

    /**
     * Wrap the stored prompt so the agent knows a routine is firing rather than
     * the user asking for one.
     *
     * The prompt tends to be stored in the user's own words, "remind me to do the
     * dishes", and it lands in a conversation where the last thing the agent did
     * was agree to schedule exactly that. Sent bare, the agent reads it as the same
     * request coming round again and answers "sure, every weekday at 7am" instead
     * of saying "do the dishes".
     */
    private function framedPrompt(): string
    {
        return 'This is a scheduled routine going off, not a new message from the user. '
            . 'It was set up earlier and is firing now on its schedule. '
            . 'Carry out the instruction below and reply with what the user should read at this moment. '
            . 'Do not schedule, reschedule, or confirm anything.'
            . PHP_EOL . PHP_EOL
            . $this->routine->prompt;
    }

    /**
     * Check if this routine targets a Slack channel (not a DM).
     * Slack DM keys are bare user IDs, while channel keys contain a colon separator.
     */
    private function isSlackChannel(): bool
    {
        return $this->routine->connector === ConnectorType::Slack
            && str_contains($this->routine->key, ':');
    }
}
