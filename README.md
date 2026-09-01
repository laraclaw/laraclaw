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
- A queue driver (Redis, database, or anything else Laravel supports)

## Installation

```bash
composer require laraclaw/laraclaw
```

Publish the config file and run the interactive setup wizard:

```bash
php artisan vendor:publish --tag=laraclaw
php artisan laraclaw:setup
```

Publishing gives you the config file and [the agent folder](#the-agent-folder), which is where you shape how the bot thinks. The wizard then walks you through migrations, owner account creation, connector configuration, and optional tools. That's it!

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

## Tool Approvals

Some operations are destructive enough that the agent should ask before running them. Deleting files, deleting mail, and deleting calendar events are gated by default. When the agent reaches one, the run pauses and you'll get the question first:

```text
⚠️ Delete `notes.txt` from disk "local"?

Reply "yes" to approve, or tell me what to do instead.
```

Reply `yes` — or `ok`, `sure`, `go ahead` — and the run resumes and carries the operation out. Reply with anything else and the call is rejected, with your own words handed back to the agent so it can act on what you actually wanted:

```text
no, delete old-notes.txt instead
```

This is built on the human-in-the-loop approvals in [laravel/ai](https://github.com/laravel/ai). You might be wondering what happens if the worker restarts while the bot is waiting. The paused run lives in the conversation history rather than in memory, so it survives a restart or a deploy and still resumes when you answer.

The terminal is the exception. Since `laraclaw:chat` is interactive, the approval is a prompt you answer in place.

### Gating Your Own Tools

You may gate an operation on any tool extending `Laraclaw\Tools\BaseTool` by adding it to `$requiresApproval` along with the question to ask. The value is either a template string interpolated with the request arguments, or a closure that receives the request:

```php
class OrderManager extends BaseTool
{
    protected array $requiresApproval = [
        'refund' => 'Refund order {order_id}?',
    ];
}
```

The agent may also override a tool's default with `requireApproval()` or `withoutApproval()` when it registers the tool.

## Your Own Tools

Extend `BaseTool` and list the operations you want to expose. It handles dispatch, so `save_attachment` in the schema calls `saveAttachment()` on your class, and an operation missing from `operations()` is rejected before it reaches your code:

```php
namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laraclaw\Tools\BaseTool;
use Laravel\Ai\Tools\Request;
use Stringable;

class OrderManager extends BaseTool
{
    protected array $requiresApproval = [
        'refund' => 'Refund order {order_id}?',
    ];

    public function description(): Stringable|string
    {
        return 'Look up and refund customer orders. Operations: find, refund.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->required()->description('Operation: find or refund'),
            'order_id' => $schema->string()->required()->description('The order ID'),
        ];
    }

    protected function operations(): array
    {
        return ['find', 'refund'];
    }

    protected function find(Request $request): string
    {
        return Order::findOrFail($request['order_id'])->summary();
    }

    protected function refund(Request $request): string
    {
        return Order::findOrFail($request['order_id'])->refund();
    }
}
```

Then name it in `config/laraclaw.php`:

```php
use App\Ai\Tools\OrderManager;

'tools' => [
    'custom' => [
        OrderManager::class,
    ],
],
```

That is the whole wiring. Tools are built once per message, and the container fills in constructor arguments named `$message` (the `IncomingMessage`) and `$thread` (the `Thread`), so a tool can tell which chat it is answering. Everything else resolves from the container as usual.

A class that does not exist or does not implement `Laravel\Ai\Contracts\Tool` is skipped with a warning in the log rather than taking down every reply, so a typo costs you one tool instead of the whole bot.

### Registering Tools at Runtime

Config is a static list, so when you need to decide per message which tools exist, register a factory instead. It receives the message and thread and runs on every turn:

```php
use Laraclaw\Tools\ToolRegistry;

$this->app->make(ToolRegistry::class)->register(
    fn (IncomingMessage $message, ?Thread $thread) => $thread?->is_direct_message
        ? new OrderManager($message)
        : new PublicOrderLookup($message),
);
```

Reach for this only when the tool list genuinely varies. For everything else the config array is easier to find and survives `config:cache`.

> [!NOTE]
> A gated call resumes from conversation history, so tools using approvals need a thread the bot remembers. Every Laraclaw connector gives them one.

## The Agent Folder

Everything that shapes how your bot thinks lives in one folder at your project root, in plain Markdown you can read in a pull request:

```
laraclaw/
  instructions.md        # base system prompt, always on
  personas/
    default.md           # voice and tone, picked up automatically
    pirate.md
  skills/
    greeting/
      SKILL.md           # loaded only when it is relevant
    report/
      SKILL.md
      reference.md
      scripts/build.sh
```

`php artisan vendor:publish --tag=laraclaw` writes the starting point: an `instructions.md`, a `personas/default.md`, and a `greeting` skill to copy from. Edit them in place and republish as often as you like, since publishing never overwrites a file that already exists.

Every file is optional. Delete the lot and the agent runs on the prompt baked into the package.

### Instructions

`laraclaw/instructions.md` replaces the base system prompt. It is always in context, so keep it about how the agent should behave in general and push anything situational into a skill.

The current date, timezone, and the sender's name are appended for you, so there is no need to mention them.

To keep the file somewhere else, point `LARACLAW_INSTRUCTIONS_PATH` at it.

### Personas

Personas sit on top of the instructions and cover voice and tone. A file named `default.md` is used automatically, so dropping one in is all you need.

To make a different one the default, name it in your `.env`:

```env
LARACLAW_PERSONAS_DEFAULT=pirate
```

That setting wins over `default.md`. Users can also switch personas at runtime just by asking the bot. Pretty neat, right?

The agent is told who sent the message, so a persona can address people by name. The name is read off the message itself, which means it identifies the actual speaker in a group chat, where the thread resolves to the configured owner.

That name comes from a profile the sender controls, so treat it as a label rather than as proof of identity. It is flattened to a single short line before it reaches the prompt, and it grants no permissions on its own.

### Skills

A skill is a folder under `laraclaw/skills/` containing a `SKILL.md`. The agent sees only the descriptions up front and pulls the full text in when it decides one is relevant, so a long skill costs nothing until it is used.

Publishing ships a `greeting` skill as a worked example. Here is a more useful one:

```markdown
---
description: Track tasks in todo.md, including who each one is assigned to and when it is due
---

Read storage/app/todo.md and keep it as a Markdown checklist...
```

**The folder name is the skill name.** Rename the folder to rename the skill. A `name` field in the frontmatter is ignored.

**The description is a routing hint, not a label.** It is the only thing the agent sees when deciding whether to reach for a skill, so describe the job that should trigger it. `Track tasks in todo.md, including who each one is assigned to` gets picked up. `Todo skill` does not.

Leave the description out and Laraclaw falls back to the first line of the file and logs a warning. The skill still loads, it is just advertised badly.

Anything else in the folder rides along. Reference material, SQL, shell scripts, templates: when the agent loads the skill it is told the directory and what is in it, so `SKILL.md` can tell it to go run `scripts/build.sh`.

```
laraclaw/skills/report/
  SKILL.md
  reference.md
  scripts/build.sh
```

New skills are picked up automatically. No code changes, no registration.

## Timezone

Laraclaw has no timezone setting of its own. It follows `config('app.timezone')`, so set it once and everything lines up:

```dotenv
APP_TIMEZONE=America/Argentina/Buenos_Aires
```

The agent is told the current time in that zone along with its UTC offset, so "remind me tomorrow at 10am" means ten in the morning where you are. Reminders, routine cron expressions, and calendar events all use it too, and anything the agent reports back is converted into it first.

If you leave `APP_TIMEZONE` unset, Laravel defaults to UTC and so does Laraclaw.

## Queue

Messages are processed via Laravel's queue. Make sure a worker is running:

```bash
php artisan queue:work
```

## License

MIT
