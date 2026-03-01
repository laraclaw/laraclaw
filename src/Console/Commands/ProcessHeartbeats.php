<?php

namespace LaraClaw\Console\Commands;

use Cron\CronExpression;
use Illuminate\Console\Command;
use LaraClaw\Jobs\SendHeartbeat;
use LaraClaw\Models\Heartbeat;

/**
 * Artisan command that dispatches SendHeartbeat jobs for active heartbeats whose cron is due.
 */
class ProcessHeartbeats extends Command
{
    protected $signature = 'laraclaw:process-heartbeats';

    protected $description = 'Dispatch SendHeartbeat jobs for all active heartbeats whose cron expression is due.';

    /**
     * Evaluate each active heartbeat's cron schedule and dispatch its job if due.
     */
    public function handle(): void
    {
        $now = now();

        Heartbeat::where('is_active', true)->each(function (Heartbeat $heartbeat) use ($now) {
            if ($heartbeat->last_run_at === null) {
                SendHeartbeat::dispatch($heartbeat);

                return;
            }

            $cron = new CronExpression($heartbeat->cron);
            $nextRun = $cron->getNextRunDate($heartbeat->last_run_at);

            if ($nextRun <= $now) {
                SendHeartbeat::dispatch($heartbeat);
            }
        });
    }
}
