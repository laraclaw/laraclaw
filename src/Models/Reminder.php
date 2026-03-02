<?php

namespace LaraClaw\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LaraClaw\Enums\ChannelType;
use LaraClaw\Tables;

/**
 * Eloquent model representing a single scheduled reminder message.
 */
class Reminder extends Model
{
    protected $table = Tables::REMINDERS;

    protected $fillable = [
        'user_id',
        'channel',
        'key',
        'message',
        'remind_at',
        'sent_at',
    ];

    /**
     * The owner of this reminder.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('laraclaw.auth.user_model'));
    }

    protected function casts(): array
    {
        return [
            'channel' => ChannelType::class,
            'remind_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
