<?php

namespace LaraClaw\Enums;

use LaraClaw\Connectors\Api;
use LaraClaw\Connectors\Connector;
use LaraClaw\Connectors\Email;
use LaraClaw\Connectors\Slack;
use LaraClaw\Connectors\Telegram;
use LaraClaw\Connectors\Terminal;
use Telegram\Bot\Api as TelegramApi;

/**
 * Supported communication connector types.
 */
enum ConnectorType: string
{
    case Telegram = 'telegram';
    case Slack = 'slack';
    case Email = 'email';
    case Terminal = 'terminal';
    case Api = 'api';

    /**
     * Instantiate the outbound connector for this type and key.
     */
    public function forKey(string $key): Connector
    {
        return match ($this) {
            self::Telegram => new Telegram((int) $key, resolve(TelegramApi::class)),
            self::Slack => Slack::forKey($key),
            self::Terminal => new Terminal,
            self::Email => Email::forKey($key),
            self::Api => Api::forKey($key),
        };
    }

    /**
     * Check if the given key represents a direct message for this connector type.
     */
    public function isDirectMessage(string $key): bool
    {
        return match ($this) {
            self::Telegram => Telegram::isDirectMessage($key),
            self::Slack => Slack::isDirectMessage($key),
            self::Email => Email::isDirectMessage($key),
            self::Terminal => Terminal::isDirectMessage($key),
        };
    }
}
