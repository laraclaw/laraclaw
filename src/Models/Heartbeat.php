<?php

namespace LaraClaw\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LaraClaw\Enums\ConnectorType;
use Override;

/**
 * Eloquent model representing a recurring prompt that fires on a cron schedule.
 */
class Heartbeat extends Model
{
    const string TABLE = 'laraclaw_heartbeats';

    protected $table = self::TABLE;

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
     * The owner of this heartbeat.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('laraclaw.auth.user_model'));
    }

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
