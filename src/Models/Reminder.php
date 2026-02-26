<?php

namespace LaraClaw\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LaraClaw\Tables;

class Reminder extends Model
{
    protected $table = Tables::REMINDERS;

    protected $fillable = [
        'user_id',
        'channel_identifier',
        'message',
        'remind_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'remind_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('laraclaw.user_model'));
    }
}
