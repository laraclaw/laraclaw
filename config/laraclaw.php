<?php

return [

    'auth' => [
        // Model to use for authentication
        'user_model' => env('LARACLAW_USER_MODEL', \App\Models\User::class),

        // ID of the admin user
        'admin_user_id' => env('LARACLAW_ADMIN_USER_ID'),
    ],

    'filesystem' => [
        // Disks the bot can access
        'allowed_disks' => array_filter(explode(',', env('LARACLAW_ALLOWED_DISKS', 'local'))),

        // Disk and directory where to store attachments (audios, images, etc.)
        'attachments_disk' => env('LARACLAW_ATTACHMENTS_DISK', 'local'),
        'attachments_path' => env('LARACLAW_ATTACHMENTS_PATH', 'attachments'),
    ],

    'logging' => [
        'agent_requests' => env('LARACLAW_LOG_AGENT_REQUESTS', false),
    ],

    // Max webhook requests per conversation per minute (0 = disabled)
    'webhook_rate_limit' => env('LARACLAW_WEBHOOK_RATE_LIMIT', 20),

    'tools' => [
        'tts' => [
            'enabled' => env('LARACLAW_TTS_ENABLED', false),
            'voice' => env('LARACLAW_TTS_VOICE', 'default-female'),
        ],

        'image_manager' => [
            // Driver to use for image processing ('imagick' or 'gd')
            'driver' => env('LARACLAW_IMAGE_DRIVER', 'imagick'),
        ],

        'calendar_manager' => [
            // Driver to use for calendar processing ('google' or 'apple', null for disabling calendar support)
            'driver' => env('LARACLAW_CALENDAR_DRIVER'),

            'google' => [
                'credentials_json' => env('LARACLAW_GOOGLE_CREDENTIALS_JSON', base_path('oauth-credentials.json')),
                'token_json' => env('LARACLAW_GOOGLE_TOKEN_JSON', base_path('oauth-token.json')),
                'calendar_id' => env('LARACLAW_GOOGLE_CALENDAR_ID'),
            ],

            'apple' => [
                'server' => env('LARACLAW_APPLE_CALDAV_SERVER', 'https://caldav.icloud.com'),
                'username' => env('LARACLAW_APPLE_CALDAV_USERNAME'),
                'password' => env('LARACLAW_APPLE_CALDAV_PASSWORD'),
                'calendar' => env('LARACLAW_APPLE_CALDAV_CALENDAR'),
            ],
        ],
    ],

    // Personas are Markdown files that extend the agent's system prompt
    'personas' => [
        // Path to the directory where the personas are stored
        'path' => env('LARACLAW_PERSONAS_PATH', base_path('laraclaw/personas')),

        // Default persona
        'default' => env('LARACLAW_PERSONAS_DEFAULT'),
    ],

    // Skills are Markdown files with YAML frontmatter that give the agent reusable instructions
    'skills' => [
        // Path to the directory where the skills are stored
        'path' => env('LARACLAW_SKILLS_PATH', base_path('laraclaw/skills')),
    ],

    'channels' => [
        'telegram' => [
            'enabled' => env('LARACLAW_TELEGRAM_ENABLED', false),
            'token' => env('LARACLAW_TELEGRAM_TOKEN'),
        ],
        'slack' => [
            'enabled' => env('LARACLAW_SLACK_ENABLED', false),
            'signing_secret' => env('LARACLAW_SLACK_SIGNING_SECRET'),
            'bot_token' => env('LARACLAW_SLACK_BOT_TOKEN'),
            'bot_user_id' => env('LARACLAW_SLACK_BOT_USER_ID'),
        ],
        'email' => [
            'enabled' => env('LARACLAW_EMAIL_ENABLED', false),

            // The bot replies only to email addresses present in this comma-separated list
            'sender_allow_list' => array_filter(explode(',', env('LARACLAW_EMAIL_SENDER_ALLOW_LIST', ''))),

            // By default, the bot rejects emails that fail DKIM or SPF authentication checks
            'verify_sender_dkim_and_spf' => env('LARACLAW_EMAIL_VERIFY_SENDER_DKIM_AND_SPF', true),

            // SMTP config for sending emails
            'smtp' => [
                'host' => env('LARACLAW_SMTP_HOST'),
                'port' => env('LARACLAW_SMTP_PORT', 587),
                'encryption' => env('LARACLAW_SMTP_ENCRYPTION', 'tls'),
                'username' => env('LARACLAW_SMTP_USERNAME'),
                'password' => env('LARACLAW_SMTP_PASSWORD'),
                'from_address' => env('LARACLAW_SMTP_FROM_ADDRESS'),
                'from_name' => env('LARACLAW_SMTP_FROM_NAME'),
            ],

            // IMAP config for reading emails
            'imap' => [
                'host' => env('LARACLAW_IMAP_HOST'),
                'port' => env('LARACLAW_IMAP_PORT', 993),
                'encryption' => env('LARACLAW_IMAP_ENCRYPTION', 'ssl'),
                'username' => env('LARACLAW_IMAP_USERNAME'),
                'password' => env('LARACLAW_IMAP_PASSWORD'),
                'mailbox' => env('LARACLAW_IMAP_MAILBOX', 'default'),
            ],
        ],
    ],
];
