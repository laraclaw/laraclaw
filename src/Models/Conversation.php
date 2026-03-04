<?php

namespace LaraClaw\Models;

use Illuminate\Database\Eloquent\Model;
use LaraClaw\Enums\ChannelType;
use LaraClaw\Tables;
use Override;

/**
 * Eloquent model representing a persistent conversation record for any channel.
 */
class Conversation extends Model
{
    protected $table = Tables::CONVERSATIONS;

    protected $fillable = ['channel', 'key', 'conversation_id', 'persona'];

    #[Override]
    protected function casts(): array
    {
        return [
            'channel' => ChannelType::class,
        ];
    }
}
