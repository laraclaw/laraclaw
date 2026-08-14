<?php

namespace Laraclaw\Models\Concerns;

use Carbon\Carbon;
use DateTimeInterface;
use Override;

use function Laraclaw\Support\appTimezone;

/**
 * Serialize model dates in the application timezone instead of UTC.
 *
 * Eloquent's default is UTC, which reads as several hours off to an agent that
 * was told the current time in a different zone. Whatever the agent is shown
 * should be in the same zone it was asked to think in.
 */
trait SerializesDatesInAppTimezone
{
    /**
     * Render a date as ISO 8601 in the application timezone, offset included.
     */
    #[Override]
    protected function serializeDate(DateTimeInterface $date): string
    {
        return Carbon::instance($date)
            ->setTimezone(appTimezone())
            ->toIso8601String();
    }
}
