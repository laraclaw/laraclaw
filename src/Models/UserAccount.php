<?php

namespace LaraClaw\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LaraClaw\Enums\ChannelType;
use LaraClaw\Tables;

class UserAccount extends Model
{
    protected $table = Tables::USER_ACCOUNTS;

    protected $fillable = ['user_id', 'channel', 'account'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('laraclaw.auth.user_model'));
    }

    protected function casts(): array
    {
        return [
            'channel' => ChannelType::class,
        ];
    }
}
