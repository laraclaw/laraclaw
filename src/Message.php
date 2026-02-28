<?php

namespace LaraClaw;

use Illuminate\Support\Collection;
use LaraClaw\Channels\Channel;
use LaraClaw\Models\UserAccount;

class Message
{
    public function __construct(
        public readonly Channel $channel,
        public readonly ?string $text = null,
        public readonly Collection $attachments = new Collection,
    ) {}

    public function isFromUnrecognizedAccount(): bool
    {
        if (! $this->channel->conversationIsDirectMessage()) {
            return false;
        }

        return ! UserAccount::where('channel', $this->channel->name)
            ->where('account', $this->channel->conversationKey())
            ->exists();
    }
}
