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
     * For DMs and Telegram groups the heartbeat continues the user's existing conversation.
     * For Slack channels each run starts a fresh thread so the channel is not cluttered.
     */
    public function handle(): void
    {
        $isSlackChannel = $this->isSlackChannel();

        // For Slack channels, strip the threadTs from the key so the reply
        // posts a new top level message instead of replying to the old thread.
        $key = $isSlackChannel
            ? explode(':', $this->heartbeat->key, 2)[0]
            : $this->heartbeat->key;

        $isDirectMessage = ! $isSlackChannel;

        $message = new IncomingMessage(
            text: $this->heartbeat->prompt,
            channel: $this->heartbeat->channel,
            key: $key,
            isDirectMessage: $isDirectMessage,
        );

        $thread = $isSlackChannel
            ? $this->freshSlackThread($key)
            : Thread::firstOrCreate(
                ['channel' => $this->heartbeat->channel, 'key' => $key],
                ['is_direct_message' => $isDirectMessage],
            );

        $agent = resolve(ChatBotAgent::class, ['message' => $message, 'thread' => $thread]);
        $response = $agent->prompt(...$message->toAgentInput());

        $thread->update(['conversation_id' => $response->conversationId]);

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

    /**
     * Create a new Thread record for a Slack channel heartbeat so each run
     * gets its own conversation with the agent.
     */
    private function freshSlackThread(string $channelId): Thread
    {
        return Thread::create([
            'channel' => ChannelType::Slack,
            'key' => $channelId,
            'is_direct_message' => false,
        ]);
    }
}
