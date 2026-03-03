<?php

namespace LaraClaw;

use Illuminate\Support\Collection;
use LaraClaw\Channels\Channel;
use LaraClaw\Models\UserAccount;

/**
 * Domain object representing an inbound message from any channel.
 */
class Message
{
    public function __construct(
        public readonly Channel $channel,
        public readonly string $conversationKey,
        public readonly bool $conversationIsDirectMessage,
        public readonly ?string $text = null,
        public readonly Collection $attachments = new Collection,
    ) {}

    /**
     * Return a copy of this message with the given text.
     */
    public function withText(string $text): self
    {
        return new self(
            $this->channel,
            $this->conversationKey,
            $this->conversationIsDirectMessage,
            $text,
            $this->attachments,
        );
    }

    /**
     * Return the UserAccount row for the admin user on the given channel, or null if none is registered.
     *
     * @return array{0: \LaraClaw\Enums\ChannelType, 1: string}|null
     */
    public function adminAccountForChannel(string $channelType): ?array
    {
        $account = UserAccount::where('user_id', config('laraclaw.auth.admin_user_id'))
            ->where('channel', $channelType)
            ->first();

        if (! $account) {
            return null;
        }

        return [$account->channel, $account->account];
    }

    /**
     * Determine whether this DM originated from an unregistered account.
     * Always returns false for group/open channel messages.
     */
    public function isFromUnrecognizedAccount(): bool
    {
        if (! $this->conversationIsDirectMessage) {
            return false;
        }

        return ! UserAccount::where('channel', $this->channel->name)
            ->where('account', $this->conversationKey)
            ->exists();
    }
}
