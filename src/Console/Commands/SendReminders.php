<?php

namespace LaraClaw\Console\Commands;

use Illuminate\Console\Command;
use LaraClaw\Jobs\SendReminder;
use LaraClaw\Models\Reminder;

/**
 * Artisan command that dispatches SendReminder jobs for all overdue reminders.
 */
class SendReminders extends Command
{
    protected $signature = 'laraclaw:send-due-reminders';

    protected $description = 'Dispatch SendReminder jobs for all reminders that are due.';

    /**
     * Dispatch a SendReminder job for every unsent reminder that is past due.
     */
    public function handle(): void
    {
        Reminder::where('remind_at', '<=', now())
            ->whereNull('sent_at')
            ->each(fn (Reminder $reminder) => SendReminder::dispatch($reminder));
    }
}
