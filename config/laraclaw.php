<?php

return [

    'channels' => [

        'telegram' => [
            'enabled' => env('LARACLAW_TELEGRAM_ENABLED', false),
        ],

        'slack' => [
            'enabled' => env('LARACLAW_SLACK_ENABLED', false),
            'bot_token' => env('SLACK_BOT_TOKEN'),
            'signing_secret' => env('SLACK_SIGNING_SECRET'),
        ],

        'email' => [
            'enabled' => env('LARACLAW_EMAIL_ENABLED', false),
            'mailbox' => env('LARACLAW_EMAIL_MAILBOX', 'default'),
        ],

    ],

    'attachments' => [
        'disk' => env('LARACLAW_ATTACHMENTS_DISK', 'local'),
        'path' => env('LARACLAW_ATTACHMENTS_PATH', 'laraclaw/attachments'),
    ],

    'disk_manager' => [
        'allowed_disks' => explode(',', env('LARACLAW_ALLOWED_DISKS', 'local,public')),
    ],

];
