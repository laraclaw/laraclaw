<?php

namespace LaraClaw\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use LaraClaw\Models\Reminder;
use LaraClaw\Support\ChannelResolver;
use Throwable;

class SendReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private Reminder $reminder,
    ) {}

    public function handle(): void
    {
        $channel = ChannelResolver::fromParts($this->reminder->channel, $this->reminder->key);
        $channel->send($this->reminder->message);
        $this->reminder->update(['sent_at' => now()]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SendReminder failed', [
            'reminder_id' => $this->reminder->id,
            'channel' => $this->reminder->channel->value,
            'key' => $this->reminder->key,
            'error' => $exception->getMessage(),
        ]);
    }
}
