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

## Channel Setup

Detailed setup instructions for each channel.

---

### Telegram

#### 1. Create a bot

Open a chat with [@BotFather](https://t.me/BotFather) on Telegram and run `/newbot`. Follow the prompts — you'll receive a bot token.

#### 2. Install the driver

```bash
composer require nutgram/laravel
```

#### 3. Set environment variables

```env
LARACLAW_TELEGRAM_TOKEN=your-bot-token
```

#### 4. Register the webhook

Your app must be publicly accessible (use [ngrok](https://ngrok.com) for local dev).

```bash
php artisan nutgram:register-webhook
```

This tells Telegram to POST new messages to `https://your-app.com/telegram/webhook`.

#### 5. Start the bot

For webhook mode (recommended in production), messages arrive automatically via the webhook route. No long-polling process needed.

For local dev without a public URL, use long-polling instead of registering a webhook:

```bash
php artisan nutgram:run
```

#### 6. Start a conversation

Search for your bot's username in Telegram and send it a message.

---

### Slack

#### 1. Create a Slack app

Go to [api.slack.com/apps](https://api.slack.com/apps) → **Create New App** → **From scratch**. Give it a name and pick a workspace.

#### 2. Add bot token scopes

Under **OAuth & Permissions** → **Bot Token Scopes**, add:

| Scope | Purpose |
|---|---|
| `chat:write` | Send messages |
| `reactions:add` | Acknowledge messages with 👍 |
| `files:read` | Download files shared by users |

#### 3. Install the app

Under **OAuth & Permissions** → **Install to Workspace**. After installing, copy the **Bot User OAuth Token** (starts with `xoxb-`).

#### 4. Enable event subscriptions

Under **Event Subscriptions**:
- Toggle **Enable Events: On**
- Set the **Request URL** to `https://your-app.com/slack/webhook`
  - Slack sends a `url_verification` challenge — your app must be running to respond. The handler does this automatically.
- Under **Subscribe to bot events**, add:
  - `message.channels` — messages in public channels
  - `message.im` — direct messages (optional)

#### 5. Set environment variables

```env
LARACLAW_SLACK_ENABLED=true
LARACLAW_SLACK_BOT_TOKEN=xoxb-your-bot-token
LARACLAW_SLACK_SIGNING_SECRET=your-signing-secret
```

The signing secret is under **Basic Information** → **App Credentials**.

#### 6. Invite the bot to a channel

In Slack, open the channel you want the bot in and run:

```
/invite @your-bot-name
```

Then send it a message.

---

### Email

#### 1. Install the IMAP driver

```bash
composer require directorytree/imapengine-laravel
```

#### 2. Configure SMTP (outgoing mail)

LaraClaw uses its own SMTP config so it doesn't interfere with your app's mail settings:

```env
LARACLAW_EMAIL_ENABLED=true
LARACLAW_MAIL_HOST=smtp.example.com
LARACLAW_MAIL_PORT=587
LARACLAW_MAIL_ENCRYPTION=tls
LARACLAW_MAIL_USERNAME=bot@example.com
LARACLAW_MAIL_PASSWORD=your-password
LARACLAW_MAIL_FROM_ADDRESS=bot@example.com
LARACLAW_MAIL_FROM_NAME="Your Bot"
```

#### 3. Configure IMAP (incoming mail)

```env
LARACLAW_IMAP_HOST=imap.example.com
LARACLAW_IMAP_PORT=993
LARACLAW_IMAP_ENCRYPTION=ssl
LARACLAW_IMAP_USERNAME=bot@example.com
LARACLAW_IMAP_PASSWORD=your-password
```

These values populate the mailbox entry in `config/imap.php` automatically. Set `LARACLAW_EMAIL_MAILBOX` if you need a non-default mailbox name.

#### 4. Allow list

Only emails from listed addresses are processed. Empty = block all.

```env
LARACLAW_EMAIL_ALLOW_LIST=alice@example.com,bob@example.com
```

#### 5. Require DKIM + SPF (optional)

Reject emails that don't pass both DKIM and SPF authentication:

```env
LARACLAW_EMAIL_REQUIRE_AUTH=true
```

#### 6. Start the IMAP listener

Add this to your `Procfile` (or run it directly):

```bash
php artisan imap:watch default --with=headers,body
```

The `--with=headers,body` flag is required — without it the message content won't be fetched.

#### 7. How it works

- Incoming emails are dispatched as `ProcessMessage` jobs
- The bot replies by sending an HTML email back to the sender
- Replies include the `In-Reply-To` and `References` headers so they thread correctly in email clients
- Conversations are scoped per email thread (using the root `Message-ID`), not per sender
- After replying, the original email is marked as read

## License

MIT
