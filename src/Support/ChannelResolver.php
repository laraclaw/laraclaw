<?php

namespace LaraClaw\Support;

use LaraClaw\Channels\Channel;
use LaraClaw\Channels\SlackChannel;
use LaraClaw\Channels\TelegramChannel;
use RuntimeException;

class ChannelResolver
{
    public static function from(string $identifier): Channel
    {
        if (str_starts_with($identifier, 'telegram:')) {
            $chatId = substr($identifier, strlen('telegram:'));
            return new TelegramChannel((int) $chatId);
        }

        if (str_starts_with($identifier, 'slack:')) {
            $parts = explode(':', $identifier, 3);

            if (count($parts) === 2) {
                // slack:{userId} — open a DM channel
                return SlackChannel::openDm($parts[1]);
            }

            // slack:{channelId}:{threadTs}
            return new SlackChannel($parts[1], $parts[2]);
        }

        if (str_starts_with($identifier, 'email:')) {
            throw new RuntimeException("Email channel does not support outbound sending via identifier: {$identifier}");
        }

        if (str_starts_with($identifier, 'terminal:')) {
            throw new RuntimeException('Terminal channel does not support outbound sending.');
        }

        throw new RuntimeException("Unknown channel identifier: {$identifier}");
    }
}
