<?php

namespace LaraClaw\Enums;

use LaraClaw\Channels\Channel;
use LaraClaw\Channels\EmailChannel;
use LaraClaw\Channels\SlackChannel;
use LaraClaw\Channels\TelegramChannel;
use LaraClaw\Channels\TerminalChannel;
use Telegram\Bot\Api;

/**
 * Supported communication channel types.
 */
enum ChannelType: string
{
    case Telegram = 'telegram';
    case Slack = 'slack';
    case Email = 'email';
    case Terminal = 'terminal';

    /**
     * Instantiate the outbound channel for this type and key.
     */
    public function forKey(string $key): Channel
    {
        return match ($this) {
            self::Telegram => new TelegramChannel((int) $key, resolve(Api::class)),
            self::Slack => SlackChannel::forKey($key),
            self::Terminal => new TerminalChannel,
            self::Email => EmailChannel::forKey($key),
        };
    }

    /**
     * Check if the given key represents a direct message for this channel type.
     */
    public function isDirectMessage(string $key): bool
    {
        return match ($this) {
            self::Telegram => TelegramChannel::isDirectMessage($key),
            self::Slack => SlackChannel::isDirectMessage($key),
            self::Email => EmailChannel::isDirectMessage($key),
            self::Terminal => TerminalChannel::isDirectMessage($key),
        };
    }
}
