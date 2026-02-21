# LaraClaw

AI-powered chatbot package for Laravel. Connects your AI agent to Telegram, Slack, Email, or the terminal — with tools for file management, web requests, image processing, calendar, email, skills, and personas.

Built on [laravel/ai](https://github.com/laravel/ai).

## Requirements

- PHP 8.2+
- Laravel 12+
- Redis

## Installation

```bash
composer require laraclaw/laraclaw
```

Publish the config and migrations:

```bash
php artisan vendor:publish --tag=laraclaw-config
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"
php artisan migrate
```

## Configuration

Set the required environment variables in your `.env`:

```env
OPENAI_API_KEY=sk-...
```

Then configure your channels.

## Channels

### Telegram

Install the Telegram driver:

```bash
composer require nutgram/laravel
```

```env
LARACLAW_TELEGRAM_TOKEN=your-bot-token
```

Register the webhook:

```bash
php artisan nutgram:register-webhook
```

Then run the bot listener:

```bash
php artisan nutgram:run
```

### Slack

```env
LARACLAW_SLACK_BOT_TOKEN=xoxb-...
LARACLAW_SLACK_SIGNING_SECRET=...
```

Point your Slack app's Event Subscriptions URL to:

```
https://your-app.com/slack/webhook
```

### Email

Install the IMAP driver:

```bash
composer require directorytree/imapengine-laravel
```

```env
LARACLAW_EMAIL_ENABLED=true
LARACLAW_EMAIL_MAILBOX=default
```

Configure your mailbox in `config/imap.php` per the [ImapEngine docs](https://github.com/DirectoryTree/ImapEngine).

## Optional Features

### Image Management

```bash
composer require spatie/image
```

### Google Calendar

```bash
composer require spatie/laravel-google-calendar
```

```env
LARACLAW_CALENDAR_DRIVER=google
LARACLAW_GOOGLE_CREDENTIALS_JSON=/path/to/oauth-credentials.json
LARACLAW_GOOGLE_TOKEN_JSON=/path/to/oauth-token.json
LARACLAW_GOOGLE_CALENDAR_ID=example@gmail.com
```

### Apple Calendar (CalDAV)

```bash
composer require sabre/vobject
```

```env
LARACLAW_CALENDAR_DRIVER=apple
LARACLAW_APPLE_CALDAV_USERNAME=your@icloud.com
LARACLAW_APPLE_CALDAV_PASSWORD=app-specific-password
LARACLAW_APPLE_CALDAV_CALENDAR=your-calendar-name
```

### Text-to-Speech

```env
LARACLAW_TTS_ENABLED=true
LARACLAW_TTS_VOICE=default-female
```

## Personas

Personas are Markdown files that override the agent's system prompt. Place them in `laraclaw/personas/` (relative to your project root):

```
laraclaw/
  personas/
    assistant.md
    developer.md
```

Set a default persona:

```env
LARACLAW_PERSONA=assistant
```

Users can switch personas at runtime by asking the bot.

## Skills

Skills are Markdown files with YAML frontmatter that give the agent reusable instructions. Place them in `laraclaw/skills/`:

```
laraclaw/
  skills/
    summarise.md
    translate.md
```

Each skill file:

```markdown
---
name: summarise
description: Summarises a given text
---

Summarise the following text in 3 bullet points...
```

## Queue

Messages are processed via Laravel's queue. Make sure a worker is running:

```bash
php artisan queue:work
```

## License

MIT
