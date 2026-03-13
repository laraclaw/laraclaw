<?php

namespace LaraClaw\Enums;

use LaraClaw\Connectors\ApiConnector;
use LaraClaw\Connectors\Channel;
use LaraClaw\Connectors\EmailConnector;
use LaraClaw\Connectors\SlackConnector;
use LaraClaw\Connectors\TelegramConnector;
use LaraClaw\Connectors\TerminalConnector;
use Telegram\Bot\Api;

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
    public function forKey(string $key): Channel
    {
        return match ($this) {
            self::Telegram => new TelegramConnector((int) $key, resolve(Api::class)),
            self::Slack => SlackConnector::forKey($key),
            self::Terminal => new TerminalConnector,
            self::Email => EmailConnector::forKey($key),
            self::Api => ApiConnector::forKey($key),
        };
    }

    /**
     * Check if the given key represents a direct message for this connector type.
     */
    public function isDirectMessage(string $key): bool
    {
        return match ($this) {
            self::Telegram => TelegramConnector::isDirectMessage($key),
            self::Slack => SlackConnector::isDirectMessage($key),
            self::Email => EmailConnector::isDirectMessage($key),
            self::Terminal => TerminalConnector::isDirectMessage($key),
        };
    }
}
