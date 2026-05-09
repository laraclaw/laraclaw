<?php

namespace Laraclaw\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Laraclaw\Agents\ChatBotAgent;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Heartbeat;
use Laraclaw\Models\Thread;
use Laraclaw\Services\Attachments;
use Throwable;

/**
 * Queued job that sends a heartbeat prompt to the agent and delivers its response.
 */
class SendHeartbeat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * Bind the heartbeat row that this job will fire on dispatch.
     */
    public function __construct(
        private Heartbeat $heartbeat,
    ) {}

    /**
     * Build an incoming message from the heartbeat prompt, run it through the agent,
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
        $isDirectMessage = $this->heartbeat->connector->isDirectMessage($this->heartbeat->key);

        // Slack channel keys are stored as "channelId:threadTs". Strip the
        // threadTs so the connector posts a new top level message each time.
        $key = $isSlackConnector
            ? explode(':', $this->heartbeat->key, 2)[0]
            : $this->heartbeat->key;

        $message = new IncomingMessage(
            text: $this->heartbeat->prompt,
            connector: $this->heartbeat->connector,
            key: $key,
            isDirectMessage: $isDirectMessage,
        );

        // For DMs and groups this finds the existing thread so the agent can
        // continue its conversation. For Slack channels the thread row is
        // reused but conversation_id is cleared below.
        $thread = Thread::firstOrCreate(
            ['connector' => $this->heartbeat->connector, 'key' => $key],
            ['is_direct_message' => $isDirectMessage],
        );

        // Null the conversation in memory so the agent starts fresh. We
        // deliberately do not persist this; see the note after prompt().
        if ($isSlackConnector) {
            $thread->conversation_id = null;
        }

        $agent = resolve(ChatBotAgent::class, ['message' => $message, 'thread' => $thread]);
        $response = $agent->prompt(...$message->toAgentInput());

        // Persist conversation_id only for channels that benefit from
        // continuity. Slack channels discard it so the next run starts clean.
        if (! $isSlackConnector) {
            $thread->update(['conversation_id' => $response->conversationId]);
        }

        $thread->connector()->reply(
            thread: $thread,
            text: $response->text,
            attachments: resolve(Attachments::class)->outbound($message->uuid)->getAll(),
        );

        $this->heartbeat->update(['last_run_at' => now()]);
    }

    /**
     * Log the failure when the job exhausts its retries.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('SendHeartbeat failed', [
            'heartbeat_id' => $this->heartbeat->id,
            'connector' => $this->heartbeat->connector->value,
            'key' => $this->heartbeat->key,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Check if this heartbeat targets a Slack channel (not a DM).
     * Slack DM keys are bare user IDs, while channel keys contain a colon separator.
     */
    private function isSlackConnector(): bool
    {
        return $this->heartbeat->connector === ConnectorType::Slack
            && str_contains($this->heartbeat->key, ':');
    }
}
