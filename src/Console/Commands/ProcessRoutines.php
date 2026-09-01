<?php

namespace Laraclaw\Console\Commands;

use Cron\CronExpression;
use Illuminate\Console\Command;
use Laraclaw\Jobs\SendRoutine;
use Laraclaw\Models\Routine;

/**
 * Artisan command that dispatches SendRoutine jobs for active routines whose cron is due.
 */
class ProcessRoutines extends Command
{
    protected $signature = 'laraclaw:process-routines';

    protected $description = 'Dispatch SendRoutine jobs for all active routines whose cron expression is due.';

    /**
     * Evaluate each active routine's cron schedule and dispatch its job if due.
     */
    public function handle(): void
    {
        $now = now();

        Routine::where('is_active', true)->each(function (Routine $routine) use ($now): void {
            if ($routine->last_run_at === null) {
                SendRoutine::dispatch($routine);

                return;
            }

            $cron = new CronExpression($routine->cron);
            $nextRun = $cron->getNextRunDate($routine->last_run_at);

            if ($nextRun <= $now) {
                SendRoutine::dispatch($routine);
            }
        });
    }
}
