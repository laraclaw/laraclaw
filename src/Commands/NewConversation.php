<?php

namespace LaraClaw\Commands;

use LaraClaw\Channels\Channel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;

class NewConversation implements Command
{
    public function prefix(): string
    {
        return '!new';
    }

    public function handle(Channel $channel, Authenticatable $user): ?string
    {
        Cache::put("new_conversation:{$user->getAuthIdentifier()}", true, 60);

        return 'Conversation reset. How can I help you?';
    }
}
