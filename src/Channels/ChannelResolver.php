<?php

namespace LaraClaw\Channels;

use LaraClaw\Enums\ChannelType;
use RuntimeException;
use SergiX44\Nutgram\Nutgram;

/**
 * Resolves a stored channel type and key into a concrete Channel instance.
 */
class ChannelResolver
{
    /**
     * Build a Channel instance from a ChannelType enum and its stored key string.
     */
    public static function fromParts(ChannelType $channel, string $key): Channel
    {
        return match ($channel) {
            ChannelType::Telegram => new TelegramChannel((int) $key, app(Nutgram::class)),
            ChannelType::Slack => str_contains($key, ':')
                ? new SlackChannel(...explode(':', $key, 2))
                : SlackChannel::openDm($key),
            ChannelType::Email => throw new RuntimeException('Email channel does not support outbound sending.'),
            ChannelType::Terminal => throw new RuntimeException('Terminal channel does not support outbound sending.'),
        };
    }
}
