<?php

namespace Laraclaw\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
class SendReminder implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Hold the unique lock for an hour so a job lost before it runs cannot block
     * the reminder forever.
     */
    public int $uniqueFor = 3600;

    /**
     * Bind the reminder row this job will deliver when it runs.
     */
    public function __construct(
        private Reminder $reminder,
    ) {}

    /**
     * Keep one queued job for each reminder row.
     */
    public function uniqueId(): string
    {
        return (string) $this->reminder->getKey();
    }

    /**
     * Resolve the connector, send the reminder message, and mark it sent.
     *
     * The dispatching command already stamped sent_at to claim the row. We write
     * it again here so the column reflects the real delivery time rather than the
     * moment the job was queued.
     */
    public function handle(): void
    {
        $thread = Thread::firstOrCreate(['connector' => $this->reminder->connector, 'key' => $this->reminder->key]);
        $thread->connector()->reply($thread, $this->reminder->message);
        $this->reminder->update(['sent_at' => now()]);
    }

    /**
     * Log the failure and hand the reminder back so a later pass can retry it.
     *
     * Clearing sent_at releases the claim the dispatching command took. Without
     * this the reminder would look delivered and would never be sent. The write
     * goes through a query so it lands even when the model we hold still has the
     * empty value it was dispatched with.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('SendReminder failed', [
            'reminder_id' => $this->reminder->id,
            'connector' => $this->reminder->connector->value,
            'key' => $this->reminder->key,
            'error' => $exception->getMessage(),
        ]);

        Reminder::whereKey($this->reminder->getKey())->update(['sent_at' => null]);
    }
}
