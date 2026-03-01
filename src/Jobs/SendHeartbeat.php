<?php

namespace LaraClaw\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use LaraClaw\Models\Heartbeat;
use LaraClaw\Support\ChannelResolver;
use Throwable;

/**
 * Queued job that delivers a single heartbeat message and records the run time.
 */
class SendHeartbeat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        private Heartbeat $heartbeat,
    ) {}

    /**
     * Resolve the channel, send the heartbeat message, and update last_run_at.
     */
    public function handle(): void
    {
        $channel = ChannelResolver::fromParts($this->heartbeat->channel, $this->heartbeat->key);
        $channel->send($this->heartbeat->message);
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
}
