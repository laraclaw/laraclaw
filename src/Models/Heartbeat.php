<?php

namespace LaraClaw\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LaraClaw\Enums\ChannelType;
use LaraClaw\Tables;
use Override;

/**
 * Eloquent model representing a recurring message that fires on a cron schedule.
 */
class Heartbeat extends Model
{
    protected $table = Tables::HEARTBEATS;

    protected $fillable = [
        'user_id',
        'channel',
        'key',
        'message',
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
            'channel' => ChannelType::class,
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }
}
