<?php

namespace Laraclaw\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Concerns\SerializesDatesInAppTimezone;
use Override;

/**
 * Eloquent model representing a single scheduled reminder message.
 */
class Reminder extends Model
{
    use SerializesDatesInAppTimezone;

    protected $table = 'laraclaw_reminders';

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

    /**
     * Cast the connector enum and the schedule and delivery timestamps.
     */
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
