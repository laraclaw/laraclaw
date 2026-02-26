<?php

namespace LaraClaw\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LaraClaw\Tables;

class UserChannel extends Model
{
    protected $table = Tables::USER_CHANNELS;

    protected $fillable = ['user_id', 'identifier'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('laraclaw.user_model'));
    }
}
