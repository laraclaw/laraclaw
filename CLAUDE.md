## Prefer Laravel idioms

- `collect(...)->filter()->implode(', ')` beats manual `array_filter` + `implode` for cleaning and joining lists.
- Use `collect($nullable ?: [$fallback])->filter()` to unify single/batch parameters into one collection.

---

## Style Guide

### File & Namespace Structure

```php
<?php

namespace LaraClaw\SomeNamespace;

use Illuminate\Support\Collection;
use LaraClaw\Message;
use LaraClaw\Models\Conversation;
use Laravel\Ai\Ai;
```

- Namespace declaration immediately after `<?php`, blank line, then `use` statements.
- No `use` for classes in the same namespace.
- One import per line. No wildcard imports.
- Logical grouping order: PHP stdlib → Laravel/Illuminate → package-internal → external libraries.
- Within each group, alphabetical order.

---

### Class Declaration

```php
class SlackChannel extends Channel implements SupportsAcknowledgement, SupportsConfirmation
{
    use ChecksRedisForConfirmations;

    private const AWAITING_KEY = 'awaiting_confirm:';

    /** @var Attachment[] */
    private array $attachments = [];

    public function __construct(
        private string $channelId,
        private ?string $threadTs = null,
    ) {}
```

- Opening brace on the same line as `class`.
- `use` trait declarations immediately after the opening brace, followed by a blank line.
- Constants next, then property declarations, then constructor.
- No blank line before the first trait/constant/property; blank line *after* each group.
- One blank line between every method definition; no blank line at the very end of the class body.

---

### Constants

```php
private const AWAITING_KEY = 'awaiting_confirm:';
private const CONFIRM_KEY  = 'confirm:';
```

- `SCREAMING_SNAKE_CASE`.
- Default visibility is `private`; only public when part of a contract.
- No inline comments; the name should be self-documenting.

---

### Property Declarations

```php
/** @var array<string, Command> */
private array $commands = [];

/** @var Attachment[] */
private array $attachments = [];
```

- Typed properties everywhere; never bare `var`.
- Docblock `@var` only when the type hint is not expressive enough (generics, element types).
- Default values on the same line as the declaration.
- `private` by default; escalate to `protected`/`public` only when necessary.

---

### Constructor & Property Promotion

```php
// Services / channels
public function __construct(
    private Message $message,
    private SkillRegistry $skillRegistry,
    private Collection $replyAttachments,
    private ?Conversation $conversation = null,
) {}

// DTOs / value objects
readonly class CalendarEvent
{
    public function __construct(
        public ?string $title = null,
        public ?DateTimeImmutable $start = null,
        public ?DateTimeImmutable $end = null,
    ) {}
}
```

- Always use **constructor property promotion** — never assign `$this->foo = $foo` manually.
- Services and channels use `private` promoted properties.
- DTOs and events use `public readonly` promoted properties (or `readonly class`).
- Nullable optional params: `?Type $name = null`, always at the end.
- Empty constructor body written as `{}` on the same line with a space: `...) {}`.
- No logic inside a constructor body; push it to a named method if needed.

---

### Method Signatures & Return Types

```php
/**
 * Return the tool instances available to the agent.
 */
public function tools(): iterable

/**
 * List all events within the given date range via a CalDAV REPORT request.
 *
 * @return \LaraClaw\DTOs\CalendarEvent[]
 */
public function list(DateTimeInterface $start, DateTimeInterface $end): array

public function send(string $message): void
```

- **Every** public method has an explicit return type — including `: void`.
- Private/protected methods too, unless the type is trivially obvious and very short.
- Union types where appropriate: `int|string`, `string|null` (prefer `?string`).
- Docblock is **one line** when the method is obvious; multi-line when you need `@return`,
  `@throws`, or a note explaining *why* the method exists.
- Never repeat the type in `@param` when the type hint already says it.
- Only `@return` when the hint alone is ambiguous (e.g. `array` → `@return CalendarEvent[]`).

---

### Method Visibility Order

Within a class, methods appear in this order:

1. `public` instance methods
2. `public static` factory/utility methods
3. `protected` methods (rare)
4. `private` methods

Related methods are grouped together regardless of visibility, because cohesion beats strict alphabetical ordering.

---

### Docblocks

```php
/**
 * Return the conversation key used to scope Redis confirmation state.
 */
private function conversationKey(): string

/**
 * Download a Slack-hosted file and persist it to the configured disk.
 *
 * Returns null when the download fails so callers can skip the attachment
 * rather than throwing.
 *
 * @return Attachment|null
 */
private static function downloadFile(array $file, string $disk, string $basePath): ?Attachment
```

- All `/** */` style (no `//` or `/* */` for docblocks).
- One blank line between the description and any `@` tags.
- No `@param` when type hints are sufficient.
- Write descriptions in third person imperative: "Return …", "Download …", "Resolve …".
- Docblock on abstract properties too.

---

### Inline Comments

```php
// If no confirmation is pending for this conversation, the incoming message
// is not a reply to a pending confirm prompt and we should not intercept it.
// Returning false signals that the message can proceed as normal.
if (! Redis::exists(self::AWAITING_KEY . $key)) {
    return false;
}
```

- Comments explain **why**, never **what**.
- Multi-line `//` comments (not `/* */`) for prose explanations.
- Place the comment on the line(s) **above** the code it describes, never trailing.
- No commented-out dead code. Delete it.
- **No em-dashes and no hyphens** in comment prose. Rephrase instead.

---

### Comment Voice & Style

Write comments the way a person would explain something to a teammate. Avoid robotic or overly formal phrasing. No em-dashes. No hyphenated compound modifiers.

**Docblocks — before / after**

```php
// BEFORE: stiff, uses em-dash, uses hyphens
/**
 * Resolve the user, conversation, and agent, then prompt the AI and deliver the reply.
 */

// AFTER: plain, conversational
/**
 * Load the sender and their conversation, prompt the agent and deliver its response.
 */
```

```php
// BEFORE: em-dash, compound hyphen
/**
 * Return the message text, falling back to audio transcription when no text is present,
 * then append attachment metadata so the agent knows the disk/path for tool use.
 */

// AFTER: two plain sentences
/**
 * Get the message text, transcribing any audio attachment if no text was provided.
 * Then append file metadata at the end so the agent knows where to find the attachments.
 */
```

```php
// BEFORE: passive/formal, "signalling"
/**
 * Resolve the user, conversation record, and AI conversation ID for this message.
 *
 * Returns null for group messages when no owner user is configured, signalling
 * that the job should stop without error.
 */

// AFTER: direct, explains the consequence plainly
/**
 * Look up the sender and find or create their conversation record.
 *
 * Returns null if this is a group message and no owner user is configured,
 * in which case the job exits quietly.
 */
```

```php
// BEFORE: "Match and run", returns-null jargon
/**
 * Match and run a command against the message text.
 *
 * Returns null when the command fully handled the message and processing should stop.
 * Returns the (possibly modified) text when processing should continue.
 */

// AFTER: plain questions answered in order
/**
 * Check if the message matches a registered command and run it.
 *
 * Returns null if the command took full ownership of the message and nothing more should happen.
 * Otherwise returns the text to continue with, which the command may have rewritten.
 */
```

```php
// BEFORE: "operation-dispatching", hyphenated compound
/**
 * Base class for operation-dispatching tools, providing confirmation, storage, and channel helpers.
 */

// AFTER: no hyphens, says the same thing clearly
/**
 * Base class for tools that dispatch named operations, with built-in confirmation, storage, and channel helpers.
 */
```

```php
// BEFORE: "natural-language" hyphen, "ISO 8601" jargon up front
/**
 * Parse a natural-language or ISO 8601 date string into a DateTimeImmutable.
 * Returns null if the string cannot be parsed.
 */

// AFTER: plain English first
/**
 * Parse a plain English or ISO 8601 date string into a DateTimeImmutable.
 * Returns null if the value cannot be understood as a date.
 */
```

```php
// BEFORE: "Re-save" hyphen, passive
/**
 * Re-save an image at the specified quality level to reduce file size.
 */

// AFTER: active, no hyphen
/**
 * Save the image again at a lower quality level to reduce file size.
 */
```

```php
// BEFORE: "single-dimension" hyphen in parenthetical
/**
 * Resize an image to the given width and/or height (aspect ratio preserved for single-dimension).
 */

// AFTER: split into two sentences, no hyphen
/**
 * Resize an image to the given width and/or height. Aspect ratio is preserved when only one dimension is provided.
 */
```

```php
// BEFORE: "per-invocation" hyphen
/**
 * Write the prompt and response payloads to per-invocation JSON files.
 */

// AFTER: restructured
/**
 * Write the prompt and response payloads to JSON files, one pair per agent invocation.
 */
```

```php
// BEFORE: "non-actionable" hyphen
/**
 * Return a successful JSON response with a bail code for non-actionable events.
 */

// AFTER: says what we actually mean
/**
 * Return a successful JSON response with a bail code for events we choose not to process.
 */
```

**Inline comments — before / after**

```php
// BEFORE: em-dash used as a separator
// Prevent loops — ignore emails from ourselves

// AFTER: one plain sentence
// Skip emails sent from our own address to prevent reply loops.
```

```php
// BEFORE: em-dash, terse
// Allow list — empty means block all

// AFTER: full sentence
// If the allow list is empty, block everything.
```

```php
// BEFORE: em-dash mid-sentence
// Authentication check — reject unless both DKIM and SPF pass

// AFTER: direct imperative
// Reject the email if DKIM or SPF did not pass.
```

```php
// BEFORE: em-dash, "their content must not be processed further" is robotic
// Fenced code blocks first — their content must not be processed further

// AFTER: explains the reason
// Handle fenced code blocks first so their content is not touched by the rules below.
```

```php
// BEFORE: em-dash, "mid-wait" hyphen
// Always clean up both keys so they never leak, even if the job is
// killed, the connection drops, or an exception is thrown mid-wait.

// AFTER: no hyphen
// Always clean up both keys so they never leak, even if the job is
// killed, the connection drops, or an exception is thrown while we wait.
```

```php
// BEFORE: em-dash, "— may be a raw IP literal"
// No DNS records — may be a raw IP literal; fall back to gethostbyname()

// AFTER: full sentence, no em-dash
// No DNS records found, which may mean this is a raw IP literal. Fall back to gethostbyname().
```

**Summary of what to avoid**

| Instead of | Write |
|---|---|
| `— ` (em-dash) as a clause separator | A new sentence or a comma |
| `non-actionable`, `one-shot`, `auto-increments` | Rephrase without the hyphen |
| `per-invocation`, `per-user`, `cron-scheduled` | "for each invocation", "for each user", "on a cron schedule" |
| `single-dimension`, `natural-language` | "when only one dimension is given", "plain English" |
| `mid-wait`, `mid-flight` | "while we wait", "while the request is in progress" |
| `re-save`, `pre-configured` | "save again", "already configured" |

---

### Early Returns & Guard Clauses

```php
public function acknowledge(): void
{
    if (! $this->messageTs) {
        return;
    }

    Http::withToken(self::token())->post(...);
}
```

- Guard at the top; happy path at the bottom.
- No `else` or `else if` after a `return`/`throw`.
- Never nest `if` statements when an early return eliminates the need.
- Prefer positive conditions in guards (`! $foo`) to avoid reading double-negatives deeper in.

---

### Blank Lines

```php
public function handle(): void
{
    $disk = config('laraclaw.filesystem.attachments_disk', 'local');
    $path = $disk . '/' . $this->filename;

    if (! Storage::disk($disk)->exists($path)) {
        return;
    }

    $contents = Storage::disk($disk)->get($path);

    return $this->process($contents);
}
```

- **No** blank line at the very start or end of a method body.
- Single blank line between **logical groups** of statements.
- Single blank line before a `return` that follows substantive setup code.
- No double-blank lines anywhere inside a method.
- One blank line between method definitions.

---

### Strings

```php
// Static content → single quotes
$type = 'application/json';

// Dynamic content → double quotes with interpolation
Log::warning("Slack file download error for {$url}");

// Concatenation with .  (never interpolate complex expressions)
->subject('Re: ' . ($this->subject ?? 'No Subject'));

// Multiline content → heredoc
'body' => <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <c:calendar-query>...</c:calendar-query>
    XML,
```

- Single quotes for literals; double quotes when interpolating.
- Interpolate simple variables (`$foo`, `{$foo->bar}`); concatenate complex expressions.
- Heredoc (`<<<LABEL`) for multiline blobs (XML, SQL fragments, long prompts).

---

### Arrays

```php
// Short, flat arrays → one line
$headers = ['Content-Type' => 'application/xml', 'Depth' => '1'];

// Longer arrays → trailing comma, one element per line
$tools = [
    new UseSkill($this->skillRegistry),
    new ImageManager($this->message, $this->replyAttachments),
    new Files($this->message, $this->replyAttachments),
];
```

- Always use `[]` (never `array()`).
- Trailing comma on the last element of a multi-line array.
- Named keys when the meaning would otherwise be unclear.
- Never mix inline and multi-line styles in the same array.

---

### Collections

```php
// Transform + return
return collect($events)
    ->map(fn (SpatieEvent $event) => new CalendarEvent(
        title: $event->name ?? '',
        start: new DateTimeImmutable($event->startDateTime->toIso8601String()),
    ))
    ->all();

// Filter falsy values, extract keys
$defaults = collect([
    'telegram' => config('laraclaw.channels.telegram.token'),
    'slack'    => config('laraclaw.channels.slack.bot_token'),
])->filter()->keys()->all();

// Single dispatch per item
Reminder::due()->each(fn (Reminder $r) => SendReminder::dispatch($r));
```

- Prefer `collect()` over manual `array_*` chains.
- Each chained call on its own line.
- `->all()` to unwrap back to a plain array.
- Arrow functions (`fn`) for single-expression callbacks; named closures for multi-statement.
- `->filter()` without callback to strip falsy values.

---

### Arrow Functions & Closures

```php
// Arrow function — single expression, implicit outer-scope capture
->map(fn (SpatieEvent $event) => new CalendarEvent(...))

// Named closure — multi-statement or needs use()
Cache::remember($key, 3600, function () {
    $response = $this->http()->send(...);
    // ... several lines
    return $url;
});
```

- Use `fn` for one-liners; use `function` for anything with more than one statement.
- Type-hint closure parameters when the type is not obvious from context.
- Never use `use (&$ref)` pass-by-reference unless absolutely necessary.

---

### Conditionals & Match

```php
// Match for exhaustive dispatch
match ($channel) {
    'telegram' => $this->setupTelegram($user),
    'slack'    => $this->setupSlack($user),
    'email'    => $this->setupEmail($user),
};

// Ternary only for simple inline defaults
$filename = $attachment->filename() ?? 'attachment.' . ($attachment->extension() ?? 'bin');
```

- `match` over `switch` for value-based dispatch.
- Ternary (`? :`) only for single-expression defaults, never chained.
- Null-coalescing (`??`) for fallback values; nullsafe (`?->`) for property chains.
- `! $foo` not `$foo === false` / `$foo === null` unless strictness matters.

---

### Exception Handling

```php
// Throw with context
if (! $response->successful() || ! $channelId) {
    throw new RuntimeException("Failed to open Slack DM with user {$userId}: " . $response->body());
}

// Catch the most specific type available
try {
    UserAccount::create([...]);
} catch (UniqueConstraintViolationException) {
    $this->error("Account already registered: {$channel->value}:{$identifier}");
    return self::FAILURE;
}

// Throwable for "log and continue" API calls
try {
    $response = Http::withToken(self::token())->get($url);
} catch (Throwable $e) {
    Log::warning('Slack file download error', ['url' => $url, 'error' => $e->getMessage()]);
    return null;
}

// finally for guaranteed cleanup
try {
    $file->save($tempPath);
    Storage::disk($disk)->put($path, file_get_contents($tempPath));
} finally {
    if (file_exists($tempPath)) {
        unlink($tempPath);
    }
}
```

- Catch the **most specific** exception type available.
- Use `Throwable` only for "log-and-return-null" resilience patterns.
- Exception messages include context variables so they are searchable.
- `finally` for guaranteed teardown (temp files, connections).
- Never swallow exceptions silently.

---

### Null Safety

```php
// Nullsafe chain
$persona = $this->conversation?->persona ?? config('laraclaw.personas.default');

// Null coalescing chain
$text = $message->text() ?? stripHtml($message->html()) ?? '';

// Guard before dereferencing
if (! $url) {
    return null;
}
```

- `?->` for safe property/method chains on nullable objects.
- `??` for fallback values; chain them: `$a ?? $b ?? $default`.
- An explicit guard + early return is clearer than a deeply nested `?->` chain.

---

### Static Factory Methods

```php
public static function openDm(string $userId): self
{
    $response = Http::withToken(self::token())
        ->post('https://slack.com/api/conversations.open', ['users' => $userId]);

    $channelId = $response->json('channel.id');

    if (! $response->successful() || ! $channelId) {
        throw new RuntimeException("Failed to open Slack DM with user {$userId}: " . $response->body());
    }

    return new self(channelId: $channelId);
}
```

- Named constructors are `public static` and return `self`.
- The method name describes the *intent*: `openDm`, `parseIncomingMessage`, `fromArray`.
- Guard clause before `return new self(...)`.
- Use **named arguments** in the `new self(...)` call so it reads like documentation.

---

### Named Arguments

```php
return new CalendarEvent(
    title: $event->name ?? '',
    start: new DateTimeImmutable($event->startDateTime->toIso8601String()),
    end:   new DateTimeImmutable($event->endDateTime->toIso8601String()),
);
```

- Use named arguments whenever the constructor/function has more than two parameters,
  or whenever the parameter meaning is not obvious from position.

---

### Method Chaining / Fluent Builders

```php
Http::withToken(self::token())
    ->post('https://slack.com/api/reactions.add', [
        'channel'   => $this->channelId,
        'name'      => 'thumbsup',
        'timestamp' => $this->messageTs,
    ]);
```

- Each chained call on its own line, indented 4 spaces from the variable.
- No intermediate variables unless the intermediate result is reused.
- Array arguments to chained calls are always multi-line with trailing comma.

---

### Config & Environment Access

```php
$token = config('laraclaw.channels.slack.bot_token');
$disk  = config('laraclaw.filesystem.attachments_disk', 'local');
$path  = config('laraclaw.filesystem.attachments_path', 'attachments') . '/email';
```

- `config()` everywhere outside of `config/*.php` files.
- Always provide a sensible default as the second argument.
- Never call `env()` outside of config files.
- Call `config()` at the point of use, not in the constructor.

---

### Facades vs Helpers

```php
// Facades for side-effectful services
Redis::exists($key);
Http::withToken($token)->post($url, $payload);
Storage::disk($disk)->put($path, $contents);
Log::warning('Failed', ['context' => $e->getMessage()]);
Cache::remember($key, $ttl, fn () => $this->fetch());

// Helpers are fine for framework utilities
now(), collect(), config(), filled(), blank(), base_path(), str()
```

- Do not use `app()` or `resolve()` to pull dependencies out of the container inside methods;
  declare them in the constructor instead.

---

### Enums

```php
enum ChannelType: string
{
    case Telegram = 'telegram';
    case Slack    = 'slack';
    case Email    = 'email';
    case Terminal = 'terminal';
}

$channel = ChannelType::tryFrom($input);

if (! $channel) {
    $this->error("Invalid channel '{$input}'.");
    return self::FAILURE;
}
```

- Backed enums with lowercase string values.
- Case names in `PascalCase`; values in `lowercase`.
- `tryFrom()` at boundaries; guard + early return if invalid.
- Never compare raw strings where an enum case exists.

---

### Abstract Classes & Property Hooks

```php
abstract class Channel
{
    abstract public string $name { get; }
    abstract public function send(string $message): void;
}

class EmailChannel extends Channel
{
    public string $name { get { return 'email'; } }
}
```

- PHP 8.4 property hooks for abstract `$name` on `Channel`.
- DTOs use `readonly class` — no hooks needed.

---

### DTOs & Value Objects

```php
readonly class CalendarEvent
{
    public function __construct(
        public ?string $title = null,
        public ?DateTimeImmutable $start = null,
        public ?DateTimeImmutable $end = null,
    ) {}
}
```

- `readonly class` for pure value objects.
- All properties `public` (readonly enforces immutability).
- Default every property to `null` so callers can use named arguments selectively.
- No business logic methods.

---

### Events

```php
class TelegramMessageReceived
{
    public function __construct(
        public readonly Message $message,
        public readonly Nutgram $bot,
    ) {}
}
```

- Plain classes; no base class required.
- All properties `public readonly`.
- No business logic.
- Named after a **past-tense fact**: `MessageReceived`, `RemindersDispatched`.

---

### Traits

- One trait, one responsibility. No mega-traits.
- Prefer an interface + trait pair: the interface declares the contract, the trait provides the default implementation.
- Never use a trait just to share private helpers between two closely related classes; extract a service instead.

---

### Variable Naming

- Descriptive over terse: `$senderEmail` beats `$email` when context is ambiguous.
- Prefix hints: `$temp*`, `$base*`, `$source*`, `$pending*`.
- ID suffix: `$userId`, `$channelId`, `$messageId`.
- Boolean variables/methods: `$isDirect`, `isFromUnrecognizedAccount()`, `supportsThreading()`.
- Avoid abbreviations unless universally understood (`$uid`, `$url`, `$ts` in Slack context).
- Loop/closure short names only when the type is obvious: `fn (Reminder $r) =>`.

---

### Artisan Commands

```php
public function handle(): int
{
    $channelValue = $this->argument('channel');
    $channel = ChannelType::tryFrom($channelValue);

    if (! $channel) {
        $this->error("Invalid channel '{$channelValue}'.");
        return self::FAILURE;
    }

    // happy path ...

    return self::SUCCESS;
}
```

- `handle()` returns `int`; use `self::SUCCESS` / `self::FAILURE`.
- Guard clauses at the top of `handle()`.
- `$this->error()`, `$this->info()`, `$this->warn()` — never `echo`.
- Argument/option parsing → validation → action.

---

### Logging

```php
Log::warning('Slack file download error', ['url' => $url, 'error' => $e->getMessage()]);
```

- `Log::warning` for recoverable failures; `Log::error` for unrecoverable ones.
- Always pass a context array as the second argument; never interpolate context into the message.
- Message is a static string; context is structured data.

---

### What to Avoid

| Anti-pattern | Preferred |
|---|---|
| `array_filter` + `implode` | `collect()->filter()->implode()` |
| `array_map` with index | `collect()->map()` |
| `switch` for value dispatch | `match` |
| `app()` / `resolve()` mid-method | Constructor injection |
| `env()` outside config files | `config()` |
| Trailing `else` after `return` | Remove the `else` |
| Nested if/else | Guard clause + early return |
| Generic `catch (Exception $e)` | Specific exception class |
| Silent swallowed exceptions | Log and/or rethrow |
| `array()` syntax | `[]` syntax |
| Positional args on large constructors | Named arguments |
| `protected` by default | `private` unless extension is needed |
| Comments explaining *what* | Comments explaining *why* |
| `var_dump` / `dd` left in | Remove before committing |
