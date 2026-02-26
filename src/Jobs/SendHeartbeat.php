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

class SendHeartbeat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        private Heartbeat $heartbeat,
    ) {}

    public function handle(): void
    {
        $channel = ChannelResolver::from($this->heartbeat->channel_identifier);
        $channel->send($this->heartbeat->message);
        $this->heartbeat->update(['last_run_at' => now()]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendHeartbeat failed', [
            'heartbeat_id' => $this->heartbeat->id,
            'channel' => $this->heartbeat->channel_identifier,
            'error' => $exception->getMessage(),
        ]);
    }
}
