# Laraclaw

[![Latest Version on Packagist](https://img.shields.io/packagist/v/laraclaw/laraclaw.svg)](https://packagist.org/packages/laraclaw/laraclaw)
[![Laravel](https://img.shields.io/badge/Laravel-12-red.svg)](https://laravel.com)
[![Code Style](https://img.shields.io/badge/code%20style-Laravel%20Pint-blue.svg)](https://github.com/laravel/pint)
[![PHP Version](https://img.shields.io/packagist/php-v/laraclaw/laraclaw.svg)](https://packagist.org/packages/laraclaw/laraclaw)
[![Tests](https://img.shields.io/github/actions/workflow/status/laraclaw/laraclaw/tests.yml?branch=main)](https://github.com/laraclaw/laraclaw/actions)
[![License](https://img.shields.io/github/license/laraclaw/laraclaw)](https://github.com/laraclaw/laraclaw/blob/main/LICENSE)

What if your Laravel app could talk back? Laraclaw is an AI chatbot package that connects your agent to **Telegram, Slack, Email, and the terminal** — with persistent memory, file handling, calendar access, reminders, and more.

Built on [laravel/ai](https://github.com/laravel/ai).

## Requirements

- PHP 8.4+
- Laravel 12+
- Redis

## Installation

```bash
composer require laraclaw/laraclaw
```

Publish the config file and run the interactive setup wizard:

```bash
php artisan vendor:publish --tag=laraclaw
php artisan laraclaw:setup
```

The wizard will walk you through migrations, owner account creation, connector configuration, and optional tools. That's it!

## Connectors

Laraclaw has a single owner — one user who controls the bot. All connectors route messages through that user.

| Connector | Who can message | Threading | Conversation scope |
|---|---|---|---|
| Telegram DM | Owner only | — | Per user |
| Telegram group | Anyone | — | Per group |
| Slack DM | Owner only | No | Per user |
| Slack channel | Anyone (@mentioned) | Always threads | Per thread |
| Email | Owner only | — | Per email thread |
| API | Any token holder | Via `key` param | Per key |
| Terminal | Owner | — | Per session |

**DM connectors** (Telegram DM, Slack DM, Email) ignore anyone who isn't registered as the owner. **Group/open connectors** always respond using the owner user. The **API connector** authenticates via a hashed Bearer token and is open to any user with a valid token.

### Telegram

You'll need a bot token from [@BotFather](https://t.me/BotFather). The setup wizard will prompt for it and print the webhook URL you need to register with Telegram:

```
https://your-app.com/telegram/webhook
```

Point your bot at it with a one-off `setWebhook` call (curl, Postman, or `Telegram::setWebhook(['url' => ...])` from Tinker).

### Slack

Create a Slack app at [api.slack.com/apps](https://api.slack.com/apps) and add these bot token scopes: `chat:write`, `reactions:add`, `files:read`. Then point your Event Subscriptions URL at:

```
https://your-app.com/slack/webhook
```

Subscribe to `message.channels` and `message.im`.

### API

The API connector exposes a token authenticated endpoint for programmatic access. Run the setup wizard or the standalone connector command to generate a token:

```bash
php artisan laraclaw:setup-connector api
```

Send a `POST` request with the Bearer token from setup:

```bash
curl -X POST https://your-app.com/api/message \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"text": "Hello"}'
```

**Request parameters:**

| Parameter | Type | Required | Description |
|---|---|---|---|
| `text` | string | Yes (unless attachments sent) | The message text |
| `key` | string | No | Pass a key from a previous response to continue that conversation. Omit to start a new one. |
| `attachments` | file[] | No | Uploaded files |

**Response:**

```json
{
  "success": true,
  "text": "Agent reply here",
  "key": "550e8400-e29b-41d4-a716-446655440000",
  "attachments": []
}
```

Pass the returned `key` in your next request to continue the conversation.

### Email

Laraclaw uses its own SMTP and IMAP config, so it won't interfere with your app's existing mail setup. The setup wizard will prompt you for both. After that, start the IMAP listener:

```bash
php artisan imap:watch default --with=headers,body
```

Replies thread correctly in email clients using `In-Reply-To` and `References` headers. Conversations are scoped per email thread, not per sender.

## Optional Tools

### Calendar

The setup wizard lets you pick between **Google Calendar** and **Apple CalDAV**. For Google, it will walk you through the OAuth flow. For Apple, you'll need an app-specific password.

### Text-to-Speech

Enable TTS in your `.env`:

```env
LARACLAW_TTS_ENABLED=true
LARACLAW_TTS_VOICE=default-female
```

## Personas

Personas are Markdown files that override the agent's system prompt. Drop them in `laraclaw/personas/` at your project root:

```
laraclaw/
  personas/
    assistant.md
    developer.md
```

Set a default in your `.env`:

```env
LARACLAW_PERSONAS_DEFAULT=assistant
```

Users can switch personas at runtime just by asking the bot. Pretty neat, right?

## Skills

Skills are Markdown files with YAML frontmatter that give the agent reusable instructions. Each skill lives in its own directory under `laraclaw/skills/` as a `SKILL.md` file:

```
laraclaw/
  skills/
    summarise/
      SKILL.md
```

```markdown
---
name: summarise
description: Summarises a given text
---

Summarise the following text in 3 bullet points...
```

The agent picks up new skills automatically — no code changes needed.

## Queue

Messages are processed via Laravel's queue. Make sure a worker is running:

```bash
php artisan queue:work
```

## License

MIT
