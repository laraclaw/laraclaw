<?php

namespace LaraClaw\Models;

use Illuminate\Database\Eloquent\Model;
use LaraClaw\Enums\ChannelType;
use LaraClaw\Tables;

class ChannelConversation extends Model
{
    protected $table = Tables::CHANNEL_CONVERSATIONS;

    protected $fillable = ['channel', 'key', 'conversation_id'];

    protected function casts(): array
    {
        return [
            'channel' => ChannelType::class,
        ];
    }
}
