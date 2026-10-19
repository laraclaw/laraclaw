<?php

use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;

uses(Laraclaw\Tests\TestCase::class)->in('Feature');
uses(Laraclaw\Tests\E2E\E2ETestCase::class)->in('E2E');
pest()->group('e2e')->in('E2E');

/**
 * Run the rest of the test as if the application lived in the given timezone.
 *
 * Laravel hands app.timezone to PHP at boot and Eloquent reads datetimes back
 * in whatever PHP is set to, so the two have to move together or the test is
 * exercising a state no real app is ever in. TestCase puts PHP back to UTC
 * afterwards.
 */
function withTimezone(string $timezone): void
{
    config(['app.timezone' => $timezone]);
    date_default_timezone_set($timezone);
}

/**
 * Free the unique job lock a queued job is holding.
 *
 * A real worker does this once the job has run or failed. Tests call it between
 * two scheduler passes so the second pass is stopped by the database claim
 * rather than by the lock, which is the behavior worth pinning down.
 */
function releaseUniqueLock(object $job): void
{
    (new UniqueLock(Cache::store()))->release($job);
}
