<?php

namespace Laraclaw\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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
class SendRoutine implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * Bind the routine row that this job will fire on dispatch.
     */
    public function __construct(
        private Routine $routine,
    ) {}

    /**
     * Serialize this run against the messages arriving on the same thread.
     *
     * A routine prompts the agent and saves the conversation like any inbound
     * message does, so it has to wait its turn or the two overwrite each other.
     */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping(Thread::lockKeyFor($this->routine->connector, $this->threadKey()))
                ->shared()
                ->withPrefix('')
                ->releaseAfter(config('laraclaw.queue.thread_lock.retry_after', 5))
                ->expireAfter(config('laraclaw.queue.thread_lock.expires_after', 900)),
        ];
    }

    /**
     * Build an incoming message from the routine prompt, run it through the agent,
     * and deliver the response via the connector.
     *
     * Conversation behavior depends on the connector:
     *
     * DMs (Slack, Telegram, Email) and Telegram groups:
     *   Continue the user's existing conversation so the agent has full context
     *   of prior interactions. The Thread row is reused and conversation_id is
     *   persisted after each run.
     *
     * Slack channels (non DM):
     *   Each run posts a new top level message and starts a fresh agent
     *   conversation. The threadTs is stripped from the key so the reply does
     *   not land inside the old Slack thread, and conversation_id is nulled in
     *   memory without writing it back so no state carries over between runs.
     */
    public function handle(): void
    {
        $isSlackConnector = $this->isSlackConnector();
        $isDirectMessage = $this->routine->connector->isDirectMessage($this->routine->key);

        $key = $this->threadKey();

        $message = new IncomingMessage(
            text: $this->routine->prompt,
            connector: $this->routine->connector,
            key: $key,
            isDirectMessage: $isDirectMessage,
        );

        // For DMs and groups this finds the existing thread so the agent can
        // continue its conversation. For Slack channels the thread row is
        // reused but conversation_id is cleared below.
        $thread = Thread::firstOrCreate(
            ['connector' => $this->routine->connector, 'key' => $key],
            ['is_direct_message' => $isDirectMessage],
        );

        // Null the conversation in memory so the agent starts fresh. We
        // deliberately do not persist this; see the note after prompt().
        if ($isSlackConnector) {
            $thread->conversation_id = null;
        }

        $agent = resolve(ChatBotAgent::class, ['message' => $message, 'thread' => $thread]);
        $response = $agent->prompt(...$message->toAgentInput());

        // A routine fires with nobody waiting on it, so a gated tool call cannot be
        // answered inline. Slack channels throw their conversation away after each run
        // and so could never be resumed; reject there and let the agent report back.
        if ($response->hasPendingApprovals() && $isSlackConnector) {
            $response = $agent
                ->continue($response->conversationId, as: $thread->user())
                ->prompt(Decision::rejectAll('Automated routine runs cannot approve tool calls.'));
        }

        // Persist conversation_id only for channels that benefit from
        // continuity. Slack channels discard it so the next run starts clean.
        if (! $isSlackConnector) {
            $thread->update(['conversation_id' => $response->conversationId]);
        }

        // Everywhere else the thread is resumable, so the pause becomes a question the
        // user can answer with their next message like any other approval.
        $question = $isSlackConnector ? null : resolve(ApprovalFlow::class)->capture($thread, $response);

        $thread->connector()->reply(
            thread: $thread,
            text: $question ?? $response->text,
            attachments: resolve(Attachments::class)->outbound($message->uuid)->getAll(),
        );

        $this->routine->update(['last_run_at' => now()]);
    }

    /**
     * Log the failure when the job exhausts its retries.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('SendRoutine failed', [
            'routine_id' => $this->routine->id,
            'connector' => $this->routine->connector->value,
            'key' => $this->routine->key,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Return the thread key this routine runs against.
     *
     * Slack channel keys are stored as "channelId:threadTs". Strip the threadTs
     * so the connector posts a new top level message each time.
     */
    private function threadKey(): string
    {
        return $this->isSlackConnector()
            ? explode(':', $this->routine->key, 2)[0]
            : $this->routine->key;
    }

    /**
     * Check if this routine targets a Slack channel (not a DM).
     * Slack DM keys are bare user IDs, while channel keys contain a colon separator.
     */
    private function isSlackConnector(): bool
    {
        return $this->routine->connector === ConnectorType::Slack
            && str_contains($this->routine->key, ':');
    }
}
