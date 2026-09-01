<?php

namespace Laraclaw\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Concerns\SerializesDatesInAppTimezone;
use Override;

/**
 * Eloquent model representing a recurring prompt that fires on a cron schedule.
 */
class Routine extends Model
{
    use SerializesDatesInAppTimezone;

    protected $table = 'laraclaw_routines';

    protected $fillable = [
        'user_id',
        'connector',
        'key',
        'prompt',
        'cron',
        'is_active',
        'last_run_at',
    ];

    /**
     * The owner of this routine.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('laraclaw.auth.user_model'));
    }

    /**
     * Cast the connector enum, the active flag, and the last-run timestamp.
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'connector' => ConnectorType::class,
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }
}
