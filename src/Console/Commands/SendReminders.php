<?php

namespace Laraclaw\Console\Commands;

use Illuminate\Console\Command;
use Laraclaw\Jobs\SendReminder;
use Laraclaw\Models\Reminder;
use Throwable;

/**
 * Artisan command that dispatches SendReminder jobs for all overdue reminders.
 */
class SendReminders extends Command
{
    protected $signature = 'laraclaw:send-due-reminders';

    protected $description = 'Dispatch SendReminder jobs for all reminders that are due.';

    /**
     * Dispatch a SendReminder job for every unsent reminder that is past due.
     *
     * Each row is claimed with a conditional update before its job goes on the
     * queue, so an overlapping scheduler pass or a second application node
     * cannot enqueue the same reminder while the first job is still waiting.
     *
     * Walking the rows by id matters here. Claiming a reminder fills sent_at,
     * which drops it out of this very query, and an offset walk would then step
     * over the reminders that shuffled down into the pages we already passed.
     */
    public function handle(): void
    {
        Reminder::where('remind_at', '<=', now())
            ->whereNull('sent_at')
            ->eachById(function (Reminder $reminder): void {
                if (! $this->claim($reminder)) {
                    return;
                }

                $this->dispatchClaimed($reminder);
            });
    }

    /**
     * Stamp sent_at only while it is still empty, and report whether we won the row.
     *
     * This is one statement, so the database picks the winner when two passes
     * reach the same reminder at the same moment. The loser skips it.
     */
    private function claim(Reminder $reminder): bool
    {
        return Reminder::whereKey($reminder->getKey())
            ->whereNull('sent_at')
            ->update(['sent_at' => now()]) === 1;
    }

    /**
     * Queue the job for a reminder we have already claimed.
     *
     * If the queue itself rejects the job the claim has to come back off, or the
     * reminder would sit there looking delivered with nothing queued to deliver
     * it. The exception still travels so the failed run is visible.
     */
    private function dispatchClaimed(Reminder $reminder): void
    {
        try {
            SendReminder::dispatch($reminder);
        } catch (Throwable $exception) {
            Reminder::whereKey($reminder->getKey())->update(['sent_at' => null]);

            throw $exception;
        }
    }
}
