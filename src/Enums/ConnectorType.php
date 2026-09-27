<?php

namespace Laraclaw\Enums;

use Laraclaw\Connectors\Api;
use Laraclaw\Connectors\Connector;
use Laraclaw\Connectors\Email;
use Laraclaw\Connectors\Slack;
use Laraclaw\Connectors\Telegram;
use Laraclaw\Connectors\Terminal;
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
        return $this->connectorClass()::isDirectMessage($key);
    }

    /**
     * Return the connector implementation behind this type.
     *
     * @return class-string<Connector>
     */
    private function connectorClass(): string
    {
        return match ($this) {
            self::Telegram => Telegram::class,
            self::Slack => Slack::class,
            self::Email => Email::class,
            self::Terminal => Terminal::class,
            self::Api => Api::class,
        };
    }
}
