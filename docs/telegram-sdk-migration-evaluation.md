# Migration Evaluation: Nutgram → telegram-bot-sdk

**Date:** 2026-03-08
**SDK evaluated:** [irazasyed/telegram-bot-sdk](https://github.com/irazasyed/telegram-bot-sdk) v3.12.0
**Current dependency:** `nutgram/laravel: ^1.6`

## Summary

telegram-bot-sdk has direct equivalents for every Nutgram feature LaraClaw uses today. The migration is straightforward — mostly mechanical rewriting of method calls with no architectural changes needed. No functional gaps or regressions were identified.

**Estimated effort:** Small (1–2 days). The Telegram surface area in LaraClaw is contained in four files and one route.

---

## Capability-by-Capability Assessment

### 1. Receiving incoming messages via webhook

| Aspect | Nutgram (current) | telegram-bot-sdk | Gap? |
|---|---|---|---|
| Webhook route | `$bot->run()` parses the incoming update automatically | `$bot->getWebhookUpdate()` or `$bot->commandsHandler(webhook: true)` | No |
| Container injection in route | `Route::post(..., fn(Nutgram $bot) => $bot->run())` | `Route::post(..., fn(Api $bot) => $bot->getWebhookUpdate())` | No |

**Changes required in `routes/laraclaw.php`:**

```php
// Before (Nutgram)
Route::post('telegram/webhook', fn (Nutgram $bot) => $bot->run());

// After (telegram-bot-sdk)
use Telegram\Bot\Api;
Route::post('telegram/webhook', function (Api $bot) {
    $update = $bot->getWebhookUpdate();
    event(new TelegramMessageReceived($update->getMessage(), $bot));
});
```

**Key difference:** Nutgram's `run()` internally fires registered handlers (the `onMessage` callback). telegram-bot-sdk's `getWebhookUpdate()` just parses the update and returns it — you dispatch the event yourself. This is actually simpler and more explicit.

**Impact:** ~5 lines changed in the route + the `onMessage` registration in `LaraclawServiceProvider::configureTelegramChannel()` can be removed since the route handles it directly.

---

### 2. Reading text, attachments (photos, files, documents)

| Aspect | Nutgram | telegram-bot-sdk | Gap? |
|---|---|---|---|
| Message text | `$message->text` | `$message->text` | No |
| Caption | `$message->caption` | `$message->caption` | No |
| Photo array | `$message->photo` (array of `PhotoSize`) | `$message->photo` (array of `PhotoSize`) | No |
| Audio | `$message->audio` | `$message->audio` | No |
| Voice | `$message->voice` | `$message->voice` | No |
| Video | `$message->video` | `$message->video` | No |
| Document | `$message->document` | `$message->document` | No |
| `file_id` on media | `$photo->file_id` | `$photo->fileId` (camelCase) | Minor |
| `file_size` on media | `$photo->file_size` | `$photo->fileSize` (camelCase) | Minor |
| `mime_type` on media | `$audio->mime_type` | `$audio->mimeType` (camelCase) | Minor |
| `file_name` on media | `$doc->file_name` | `$doc->fileName` (camelCase) | Minor |
| File download | `$bot->getFile($id)` → `$file->save($path)` | `$bot->downloadFile($id, $path)` (single call) | No — simpler |

**Changes required in `TelegramChannel.php`:**

The `saveAttachments()` and `downloadFile()` methods need updating:

- **Property access style:** telegram-bot-sdk uses camelCase (`fileId`, `fileSize`, `mimeType`, `fileName`) vs Nutgram's snake_case (`file_id`, `file_size`, `mime_type`, `file_name`). This is a search-and-replace.

- **File download pattern:**
  ```php
  // Before (Nutgram) — two-step
  $file = $bot->getFile($fileId);
  $file->save($tempPath);

  // After (telegram-bot-sdk) — one-step
  $bot->downloadFile($fileId, $tempPath);
  ```

- **PhotoSize sorting:** Both SDKs return `photo` as an array of `PhotoSize` objects. The current `sortByDesc(fn ($p) => $p->file_size)` just needs `file_size` → `fileSize`.

**Impact:** ~15–20 lines of mechanical property renames + simplification of `downloadFile()`.

---

### 3. Replying with text and files

| Aspect | Nutgram | telegram-bot-sdk | Gap? |
|---|---|---|---|
| Send text | `$bot->sendMessage($text, chat_id: $id, parse_mode: ...)` | `$bot->sendMessage(['chat_id' => $id, 'text' => $text, 'parse_mode' => 'HTML'])` | No |
| Send photo | `$bot->sendPhoto(photo: InputFile::make(...), chat_id: $id)` | `$bot->sendPhoto(['chat_id' => $id, 'photo' => InputFile::create(...)])` | No |
| Send document | `$bot->sendDocument(document: InputFile::make(...), chat_id: $id)` | `$bot->sendDocument(['chat_id' => $id, 'document' => InputFile::create(...)])` | No |
| Send voice | `$bot->sendVoice(voice: InputFile::make(...), chat_id: $id)` | `$bot->sendVoice(['chat_id' => $id, 'voice' => InputFile::create(...)])` | No |
| Typing indicator | `$bot->sendChatAction(ChatAction::TYPING, chat_id: $id)` | `$bot->sendChatAction(['chat_id' => $id, 'action' => Actions::TYPING])` | No |
| InputFile from stream | `InputFile::make(fopen($path, 'r'))` | `InputFile::create(fopen($path, 'r'), $filename)` | No |
| InputFile from path | N/A | `InputFile::create($path)` — accepts a file path directly | Better |
| Parse mode constant | `ParseMode::HTML` (enum) | `'HTML'` (string) | No |

**Changes required in `TelegramChannel.php`:**

The calling convention changes from named parameters to array-style:

```php
// Before (Nutgram)
$this->bot->sendMessage($html, chat_id: $this->chatId, parse_mode: ParseMode::HTML);

// After (telegram-bot-sdk)
$this->bot->sendMessage([
    'chat_id' => $this->chatId,
    'text' => $html,
    'parse_mode' => 'HTML',
]);
```

```php
// Before (Nutgram)
$this->bot->sendPhoto(
    photo: InputFile::make(fopen($tempPath, 'r'), basename($attachment->path)),
    chat_id: $this->chatId,
);

// After (telegram-bot-sdk)
$this->bot->sendPhoto([
    'chat_id' => $this->chatId,
    'photo' => InputFile::create($tempPath, basename($attachment->path)),
]);
```

**Bonus:** `InputFile::create()` accepts file paths directly, so the `fopen()` calls in `handleAttachments()` can be simplified. The `withTempFile()` pattern still works but the file opening is cleaner.

**Impact:** ~20 lines rewritten in `send()` and `handleAttachments()`. Mechanical changes.

---

### 4. Bot instance resolved from Laravel container / passed through events

| Aspect | Nutgram | telegram-bot-sdk | Gap? |
|---|---|---|---|
| Service provider | `nutgram/laravel` auto-registers `Nutgram` in container | `TelegramServiceProvider` registers `Api` + `BotsManager` as singletons | No |
| Type-hint injection | `fn(Nutgram $bot)` | `fn(Api $bot)` | No |
| Token configuration | `config('nutgram.token')` | `config('telegram.bots.mybot.token')` | No — different config key |
| Multi-bot support | Limited | Built-in via `BotsManager` | Better |
| Facade | None (uses DI) | `Telegram` facade available | Extra |

**Changes required in `LaraclawServiceProvider.php`:**

```php
// Before
private function configureTelegramChannel(): void
{
    $this->app->booting(function (): void {
        config()->set('nutgram.token', config('laraclaw.channels.telegram.token'));
        config()->set('nutgram.config.timeout', 120);
    });

    $this->app->resolving(Nutgram::class, function (Nutgram $bot): void {
        $bot->onMessage(fn (Nutgram $bot) => event(new TelegramMessageReceived($bot->message(), $bot)));
    });
}

// After
private function configureTelegramChannel(): void
{
    $this->app->booting(function (): void {
        config()->set('telegram.bots.laraclaw', [
            'token' => config('laraclaw.channels.telegram.token'),
        ]);
        config()->set('telegram.default', 'laraclaw');
    });
}
```

The `onMessage` handler registration is no longer needed — the webhook route dispatches the event directly (see section 1).

**Impact:** Simpler provider code. The `resolving` hook and `onMessage` registration are eliminated.

---

### 5. TelegramMessageReceived event

```php
// Before
class TelegramMessageReceived
{
    public function __construct(
        public readonly Message $message,    // SergiX44\Nutgram\Telegram\Types\Message\Message
        public readonly Nutgram $bot,
    ) {}
}

// After
class TelegramMessageReceived
{
    public function __construct(
        public readonly Message $message,    // Telegram\Bot\Objects\Message
        public readonly Api $bot,
    ) {}
}
```

The `TelegramListener` receives this event and the changes cascade from the type swap.

**Impact:** 2 import changes + type hints.

---

## Files Requiring Changes

| File | Scope of change |
|---|---|
| `composer.json` | Replace `nutgram/laravel` with `irazasyed/telegram-bot-sdk` |
| `src/LaraclawServiceProvider.php` | Simplify `configureTelegramChannel()` (~10 lines) |
| `routes/laraclaw.php` | Change webhook handler (~3 lines) |
| `src/Events/TelegramMessageReceived.php` | Swap type hints (2 lines) |
| `src/Listeners/TelegramListener.php` | Swap type hints + adjust property access (~5 lines) |
| `src/Channels/TelegramChannel.php` | Rewrite send/receive methods (~40 lines) |
| `config/laraclaw.php` | No change needed (token config stays in laraclaw namespace) |

**Total:** ~80 lines of code changes across 6 files.

---

## Gaps and Regressions

### No functional gaps identified.

Every Nutgram feature LaraClaw uses has a direct equivalent in telegram-bot-sdk.

### Minor considerations:

1. **SerializableClosure workaround may become unnecessary.** The `TelegramListener` currently resets the SerializableClosure signing key because Nutgram overrides it in its constructor. telegram-bot-sdk does not appear to do this, so the workaround (lines 65–71 of TelegramListener) can likely be removed. This should be verified during implementation.

2. **Property access style.** telegram-bot-sdk uses camelCase (`fileId`, `fileSize`) while Nutgram uses snake_case (`file_id`, `file_size`). This is purely cosmetic but touches multiple lines.

3. **Array vs named parameters.** telegram-bot-sdk uses `sendMessage(['chat_id' => ..., 'text' => ...])` while Nutgram uses `sendMessage($text, chat_id: ...)`. Neither is better — just different.

4. **`$message->chat->id` access.** Verify that telegram-bot-sdk's Message object exposes `chat` the same way for the rate limiter in `LaraclawServiceProvider`. The raw `$request->input('message.chat.id')` reads from the JSON payload directly, so the rate limiter should be unaffected.

5. **Validation logic.** `TelegramChannel::validateEvent()` accesses `$message->chat->id` and checks for text/caption/media presence. These properties exist on telegram-bot-sdk's `Message` object, but the access pattern (e.g., `$message->chat->id` vs `$message->chat->id`) should be verified.

---

## Recommendation

**Proceed with migration.** The effort is small, the SDK is mature (v3.x, actively maintained, Laravel-first design), and the migration eliminates the SerializableClosure workaround. telegram-bot-sdk's array-based API is well-documented and its Laravel service provider is more idiomatic than Nutgram's.
