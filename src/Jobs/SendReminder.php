<?php

namespace Laraclaw\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Laraclaw\Models\Reminder;
use Laraclaw\Models\Thread;
use Throwable;

/**
 * Queued job that delivers a single reminder message and stamps it as sent.
 */
class SendReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Bind the reminder row this job will deliver when it runs.
     */
    public function __construct(
        private Reminder $reminder,
    ) {}

    /**
     * Resolve the connector, send the reminder message, and mark it sent.
     */
    public function handle(): void
    {
        $thread = Thread::firstOrCreate(['connector' => $this->reminder->connector, 'key' => $this->reminder->key]);
        $thread->connector()->reply($thread, $this->reminder->message);
        $this->reminder->update(['sent_at' => now()]);
    }

    /**
     * Log the failure when the job exceeds its retry limit.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('SendReminder failed', [
            'reminder_id' => $this->reminder->id,
            'connector' => $this->reminder->connector->value,
            'key' => $this->reminder->key,
            'error' => $exception->getMessage(),
        ]);
    }
}
