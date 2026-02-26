<?php

namespace LaraClaw\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LaraClaw\Tables;

class Heartbeat extends Model
{
    protected $table = Tables::HEARTBEATS;

    protected $fillable = [
        'user_id',
        'channel_identifier',
        'message',
        'cron',
        'is_active',
        'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('laraclaw.user_model'));
    }
}
