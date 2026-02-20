<?php

return [
    /*
     |--------------------------------------------------------------------------
     | User Model
     |--------------------------------------------------------------------------
     | The Eloquent model used to resolve/create bot users. Must extend
     | Illuminate\Database\Eloquent\Model and implement Authenticatable.
     */
    'user_model' => env('LARACLAW_USER_MODEL', \App\Models\User::class),

    'telegram' => [
        'enabled' => env('LARACLAW_TELEGRAM_ENABLED', true),
        'token' => env('LARACLAW_TELEGRAM_TOKEN'),
    ],

    'slack' => [
        'enabled' => env('LARACLAW_SLACK_ENABLED', true),
        'bot_token' => env('LARACLAW_SLACK_BOT_TOKEN'),
        'signing_secret' => env('LARACLAW_SLACK_SIGNING_SECRET'),
    ],

    'attachments' => [
        'disk' => env('LARACLAW_ATTACHMENTS_DISK', 'local'),
        'path' => env('LARACLAW_ATTACHMENTS_PATH', 'attachments'),
    ],

    'tools' => [
        'allowed_disks' => ['local'],
        'system_directories' => [],
    ],

    'persona' => [
        'default' => env('LARACLAW_PERSONA'),
        'path' => env('LARACLAW_PERSONAS_PATH', base_path('laraclaw/personas')),
    ],

    'skills' => [
        'path' => env('LARACLAW_SKILLS_PATH', base_path('laraclaw/skills')),
    ],

    'email' => [
        'enabled' => env('LARACLAW_EMAIL_ENABLED', false),
        'mailbox' => env('LARACLAW_EMAIL_MAILBOX', 'default'),
    ],

    'tts' => [
        'enabled' => env('LARACLAW_TTS_ENABLED', false),
        'voice' => env('LARACLAW_TTS_VOICE', 'default-female'),
    ],

    'logging' => [
        'agent_requests' => env('LARACLAW_LOG_AGENT_REQUESTS', false),
        'disk' => env('LARACLAW_LOG_DISK', 'local'),
    ],

    'calendar' => [
        'driver' => env('LARACLAW_CALENDAR_DRIVER'), // 'google' or 'apple', null = disabled

        'google' => [
            'credentials_json' => env('LARACLAW_GOOGLE_CREDENTIALS_JSON', base_path('oauth-credentials.json')),
            'token_json' => env('LARACLAW_GOOGLE_TOKEN_JSON', base_path('oauth-token.json')),
        ],

        'apple' => [
            'server' => env('LARACLAW_APPLE_CALDAV_SERVER', 'https://caldav.icloud.com'),
            'username' => env('LARACLAW_APPLE_CALDAV_USERNAME'),
            'password' => env('LARACLAW_APPLE_CALDAV_PASSWORD'),
            'calendar' => env('LARACLAW_APPLE_CALDAV_CALENDAR'),
        ],
    ],
];
