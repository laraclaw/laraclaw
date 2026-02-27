<?php

namespace LaraClaw\Enums;

enum ChannelType: string
{
    case Telegram = 'telegram';
    case Slack = 'slack';
    case Email = 'email';
    case Terminal = 'terminal';
}
