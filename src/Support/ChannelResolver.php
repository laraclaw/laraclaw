<?php

namespace LaraClaw\Support;

use LaraClaw\Channels\Channel;
use LaraClaw\Channels\SlackChannel;
use LaraClaw\Channels\TelegramChannel;
use LaraClaw\Enums\ChannelType;
use RuntimeException;

class ChannelResolver
{
    public static function from(string $identifier): Channel
    {
        [$type, $key] = explode(':', $identifier, 2);

        return self::fromParts(ChannelType::from($type), $key);
    }

    public static function fromParts(ChannelType $channel, string $key): Channel
    {
        return match ($channel) {
            ChannelType::Telegram => new TelegramChannel((int) $key),
            ChannelType::Slack => str_contains($key, ':')
                ? new SlackChannel(...explode(':', $key, 2))
                : SlackChannel::openDm($key),
            ChannelType::Email => throw new RuntimeException('Email channel does not support outbound sending.'),
            ChannelType::Terminal => throw new RuntimeException('Terminal channel does not support outbound sending.'),
        };
    }
}
