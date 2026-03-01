<?php

namespace LaraClaw\Enums;

/**
 * Supported communication channel types.
 */
enum ChannelType: string
{
    case Telegram = 'telegram';
    case Slack = 'slack';
    case Email = 'email';
    case Terminal = 'terminal';
}
