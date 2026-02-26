<?php

namespace LaraClaw\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelConversation extends Model
{
    protected $primaryKey = 'identifier';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['identifier', 'conversation_id'];
}
