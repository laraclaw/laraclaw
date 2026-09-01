<?php

namespace Laraclaw\Console\Commands;

use Illuminate\Console\Command;
use Laraclaw\Jobs\SendReminder;
use Laraclaw\Models\Reminder;

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
     */
    public function handle(): void
    {
        Reminder::where('remind_at', '<=', now())
            ->whereNull('sent_at')
            ->each(function (Reminder $reminder): void {
                if (! $this->claim($reminder)) {
                    return;
                }

                SendReminder::dispatch($reminder);
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
}
