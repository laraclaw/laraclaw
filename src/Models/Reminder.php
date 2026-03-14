<?php

namespace LaraClaw\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LaraClaw\Enums\ConnectorType;
use Override;

/**
 * Eloquent model representing a single scheduled reminder message.
 */
class Reminder extends Model
{
    const string TABLE = 'laraclaw_reminders';

    protected $table = self::TABLE;

    protected $fillable = [
        'user_id',
        'connector',
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

    #[Override]
    protected function casts(): array
    {
        return [
            'connector' => ConnectorType::class,
            'remind_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
