<?php

namespace LaraClaw\Models;

use Illuminate\Database\Eloquent\Model;
use LaraClaw\Tables;

class ChannelConversation extends Model
{
    protected $table = Tables::CHANNEL_CONVERSATIONS;

    protected $primaryKey = 'identifier';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['identifier', 'conversation_id'];
}
