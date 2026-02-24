<?php

namespace LaraClaw\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserChannel extends Model
{
    protected $fillable = ['user_id', 'identifier'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('laraclaw.user_model'));
    }
}
