<?php

namespace Laraclaw\Console\Commands;

use Carbon\CarbonInterface;
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
     *
     * Due routines have last_run_at stamped before the job is queued, so an
     * overlapping scheduler pass or a second application node sees the routine
     * as already run and cannot queue it a second time.
     */
    public function handle(): void
    {
        $now = now();

        Routine::where('is_active', true)->each(function (Routine $routine) use ($now): void {
            if (! $this->isDue($routine, $now)) {
                return;
            }

            $previousRunAt = $routine->last_run_at;

            if (! $this->claim($routine, $now)) {
                return;
            }

            SendRoutine::dispatch($routine, $previousRunAt);
        });
    }

    /**
     * Check if the routine has never run or if its next cron occurrence has passed.
     */
    private function isDue(Routine $routine, CarbonInterface $now): bool
    {
        if ($routine->last_run_at === null) {
            return true;
        }

        return new CronExpression($routine->cron)->getNextRunDate($routine->last_run_at) <= $now;
    }

    /**
     * Move last_run_at forward only while it still holds the value we read, and
     * report whether we won the row.
     *
     * This is one statement, so the database picks the winner when two passes
     * reach the same routine at the same moment. The loser skips it.
     */
    private function claim(Routine $routine, CarbonInterface $now): bool
    {
        $query = Routine::whereKey($routine->getKey());

        if ($routine->last_run_at === null) {
            $query->whereNull('last_run_at');
        } else {
            $query->where('last_run_at', $routine->last_run_at);
        }

        return $query->update(['last_run_at' => $now]) === 1;
    }
}
