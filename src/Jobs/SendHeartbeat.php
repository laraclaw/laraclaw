<?php

namespace LaraClaw\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use LaraClaw\Agents\ChatBotAgent;
use LaraClaw\DTOs\Attachment;
use LaraClaw\DTOs\IncomingMessage;
use LaraClaw\Enums\ChannelType;
use LaraClaw\Models\Heartbeat;
use LaraClaw\Models\Thread;
use Throwable;

use function LaraClaw\Support\logAgentUsage;

/**
 * Queued job that sends a heartbeat prompt to the agent and delivers its response.
 */
class SendHeartbeat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        private Heartbeat $heartbeat,
    ) {}

    /**
     * Build an incoming message from the heartbeat prompt, run it through the agent,
     * and deliver the response via the channel.
     *
     * Conversation behavior depends on the channel:
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
        $isSlackChannel = $this->isSlackChannel();
        $isDirectMessage = $this->heartbeat->channel->isDirectMessage($this->heartbeat->key);

        // Slack channel keys are stored as "channelId:threadTs". Strip the
        // threadTs so the channel posts a new top level message each time.
        $key = $isSlackChannel
            ? explode(':', $this->heartbeat->key, 2)[0]
            : $this->heartbeat->key;

        $message = new IncomingMessage(
            text: $this->heartbeat->prompt,
            channel: $this->heartbeat->channel,
            key: $key,
            isDirectMessage: $isDirectMessage,
        );

        // For DMs and groups this finds the existing thread so the agent can
        // continue its conversation. For Slack channels the thread row is
        // reused but conversation_id is cleared below.
        $thread = Thread::firstOrCreate(
            ['channel' => $this->heartbeat->channel, 'key' => $key],
            ['is_direct_message' => $isDirectMessage],
        );

        // Null the conversation in memory so the agent starts fresh. We
        // deliberately do not persist this; see the note after prompt().
        if ($isSlackChannel) {
            $thread->conversation_id = null;
        }

        $agent = resolve(ChatBotAgent::class, ['message' => $message, 'thread' => $thread]);
        $response = $agent->prompt(...$message->toAgentInput());

        // Persist conversation_id only for channels that benefit from
        // continuity. Slack channels discard it so the next run starts clean.
        if (! $isSlackChannel) {
            $thread->update(['conversation_id' => $response->conversationId]);
        }

        // Collect any files the agent wrote during tool use.
        $outboundPath = config('laraclaw.filesystem.outgoing_attachments_path', 'outbound') . '/' . $message->uuid;
        $disk = config('laraclaw.filesystem.attachments_disk', 'local');
        $attachments = collect(Storage::disk($disk)->files($outboundPath))
            ->map(fn (string $path): Attachment => new Attachment(
                filename: basename($path),
                path: $path,
                disk: $disk,
                mimeType: Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream',
            ));

        $thread->channel()->reply(
            thread: $thread,
            text: $response->text,
            attachments: $attachments->isNotEmpty() ? $attachments : null,
        );

        if ($attachments->isNotEmpty()) {
            Storage::disk($disk)->deleteDirectory($outboundPath);
        }

        logAgentUsage('heartbeat', $response->usage);
        $this->heartbeat->update(['last_run_at' => now()]);
    }

    /**
     * Log the failure when the job exhausts its retries.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('SendHeartbeat failed', [
            'heartbeat_id' => $this->heartbeat->id,
            'channel' => $this->heartbeat->channel->value,
            'key' => $this->heartbeat->key,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Check if this heartbeat targets a Slack channel (not a DM).
     * Slack DM keys are bare user IDs, while channel keys contain a colon separator.
     */
    private function isSlackChannel(): bool
    {
        return $this->heartbeat->channel === ChannelType::Slack
            && str_contains($this->heartbeat->key, ':');
    }

}
