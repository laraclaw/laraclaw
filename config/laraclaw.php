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

        // Disk and directories where to store attachments (audios, images, etc.)
        'attachments_disk' => env('LARACLAW_ATTACHMENTS_DISK', 'local'),
        'incoming_attachments_path' => env('LARACLAW_INCOMING_ATTACHMENTS_PATH', 'inbound'),
        'outgoing_attachments_path' => env('LARACLAW_OUTGOING_ATTACHMENTS_PATH', 'outbound'),
    ],

    'memory' => [
        'enabled' => env('LARACLAW_MEMORY_ENABLED', false),

        // How many relevant chunks to inject into the agent prompt
        'max_results' => env('LARACLAW_MEMORY_MAX_RESULTS', 5),

        // Minimum similarity score (0.0 to 1.0) to include a result
        'min_similarity' => env('LARACLAW_MEMORY_MIN_SIMILARITY', 0.5),
    ],

    'logging' => [
        'agent_requests' => env('LARACLAW_LOG_AGENT_REQUESTS', false),
    ],

    // Max webhook requests per conversation per minute (0 = disabled)
    'webhook_rate_limit' => env('LARACLAW_WEBHOOK_RATE_LIMIT', 20),

    'tools' => [
        'tts' => [
            'enabled' => env('LARACLAW_TTS_ENABLED', true),
            'voice' => env('LARACLAW_TTS_VOICE', 'default-female'),
        ],

        'image_manager' => [
            // Driver to use for image processing ('imagick' or 'gd')
            'driver' => env('LARACLAW_IMAGE_DRIVER', 'imagick'),
        ],

        'bash' => [
            'enabled' => env('LARACLAW_BASH_ENABLED', false),
        ],

        'tinker' => [
            'enabled' => env('LARACLAW_TINKER_ENABLED', false),
        ],

        'read_database' => [
            'enabled' => env('LARACLAW_READ_DATABASE_ENABLED', false),

            // Credentials for the readonly DB user on MySQL and Postgres. Set during the wizard.
            'username' => env('LARACLAW_READ_DATABASE_USERNAME'),
            'password' => env('LARACLAW_READ_DATABASE_PASSWORD'),

            // Per-query timeout for MySQL and Postgres. 0 disables the cap.
            'timeout_seconds' => env('LARACLAW_READ_DATABASE_TIMEOUT_SECONDS', 10),
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

    'connectors' => [
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
        'api' => [
            'enabled' => env('LARACLAW_API_ENABLED', false),
        ],
    ],
];
