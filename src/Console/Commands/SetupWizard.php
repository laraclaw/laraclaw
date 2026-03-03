<?php

namespace LaraClaw\Console\Commands;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use LaraClaw\Models\UserAccount;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

/**
 * Interactive Artisan wizard that provisions the owner account and configures channels.
 */
class SetupWizard extends Command
{
    protected $signature = 'laraclaw:setup';

    protected $description = 'Interactive setup wizard for LaraClaw';

    public function handle(): int
    {
        echo file_get_contents(__DIR__ . '/../../../resources/ascii/logo.md');

        spin(fn () => $this->callSilently('migrate', ['--force' => true]), 'Running migrations…');

        info('Welcome! LaraClaw is an AI-assistant that runs in your Laravel app.');

        $user = $this->resolveOwner();

        if (! $user) {
            return self::FAILURE;
        }

        $this->askAgentName();

        $channels = $this->selectChannels();

        foreach ($channels as $channel) {
            match ($channel) {
                'telegram' => $this->setupTelegram($user),
                'slack' => $this->setupSlack($user),
                'email' => $this->setupEmail($user),
            };
        }

        $this->selectTools();
        $this->finish($channels);

        return self::SUCCESS;
    }

    private function resolveOwner(): mixed
    {
        $userModel = config('laraclaw.auth.user_model');
        $existingId = $this->readEnv('LARACLAW_ADMIN_USER_ID');
        $existingAdmin = $userModel::find($existingId);

        $this->heading('⭐ Admin Account');
        info("First, let's setup your admin user account.");

        if ($existingAdmin && select("Admin user already exists ({$existingAdmin->email}). Keep it?", [
            'existing' => 'Yes, keep the existing admin user',
            'new' => 'No, create a new one',
        ]) === 'existing') {
            return $existingAdmin;
        }

        $user = $this->createUser($userModel);
        $this->writeEnv('LARACLAW_ADMIN_USER_ID', $user->id);

        return $user;
    }

    private function createUser(string $userModel): mixed
    {
        $name = text("👤 What's your name?", placeholder: 'E.g. Alex', required: true);

        info("Nice to meet you, {$name}!");

        $email = text(
            label: "✉️ What's your email?",
            placeholder: 'E.g. john@example.com',
            required: true,
            validate: fn (string $value) => match (true) {
                ! filter_var($value, FILTER_VALIDATE_EMAIL) => 'Please enter a valid email address.',
                $userModel::where('email', $value)->exists() => 'That email is already taken.',
                default => null,
            },
        );

        info("Great. Now, let's create your password.");

        $password = password('🔑 Password', required: true);

        password(
            label: '🔑 Repeat password',
            required: true,
            validate: fn (string $value) => $value !== $password ? 'Passwords do not match.' : null,
        );

        return $userModel::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);
    }

    private function askAgentName(): void
    {
        $existing = $this->readEnv('LARACLAW_AGENT_NAME');

        if ($existing !== null && select('Agent name', [
            'existing' => "Use existing: {$existing}",
            'new' => 'Set a new name',
        ]) === 'existing') {
            return;
        }

        info("Now, it's time to give me a name!");

        $name = text('🤖 How should I be called?', placeholder: 'E.g. Jarvis', required: true);
        $this->writeEnv('LARACLAW_AGENT_NAME', $name);

        info("Great! I'll be called {$name} from now on.");
    }

    private function selectChannels(): array
    {
        $this->heading('📫 Channels');

        $texts = implode(PHP_EOL, [
            '  - Telegram: a Telegram bot',
            '  - Slack: a Slack bot',
            '  - Email: SMTP and IMAP details',
        ]);
        info('I can receive your messages from multiple channels.');
        info('This is what we need to configure each:' . PHP_EOL . PHP_EOL . $texts);

        $defaults = collect([
            'telegram' => $this->readEnv('LARACLAW_TELEGRAM_TOKEN'),
            'slack' => $this->readEnv('LARACLAW_SLACK_BOT_TOKEN'),
            'email' => $this->readEnv('LARACLAW_SMTP_HOST'),
        ])->filter()->keys()->all();

        return multiselect(
            label: '📫 Which channels would you like to set up?',
            options: ['telegram' => 'Telegram', 'slack' => 'Slack', 'email' => 'Email'],
            default: $defaults,
            required: false,
        );
    }

    private function setupTelegram(mixed $user): void
    {
        info("✨ Let's configure Telegram.");

        $token = $this->askEnv('Bot token (from BotFather)', 'LARACLAW_TELEGRAM_TOKEN', secret: true, placeholder: '1234567890:ABCdef…');
        $chatId = $this->askAccount('Your Telegram chat ID (send /start to @userinfobot to get it)', $user->getAuthIdentifier(), 'telegram', placeholder: '123456789');

        $this->saveEnv(['LARACLAW_TELEGRAM_TOKEN' => $token, 'LARACLAW_TELEGRAM_ENABLED' => 'true']);

        UserAccount::updateOrCreate(
            ['user_id' => $user->getAuthIdentifier(), 'channel' => 'telegram'],
            ['account' => $chatId],
        );
    }

    private function setupSlack(mixed $user): void
    {
        info("✨ Let's configure Slack.");

        $botToken = $this->askEnv('Bot token', 'LARACLAW_SLACK_BOT_TOKEN', secret: true, placeholder: 'xoxb-…');
        $signingSecret = $this->askEnv('Signing secret', 'LARACLAW_SLACK_SIGNING_SECRET', secret: true, placeholder: 'a1b2c3…');
        $botUserId = $this->askEnv('Bot user ID', 'LARACLAW_SLACK_BOT_USER_ID', placeholder: 'U1234567890');
        $ownerSlackId = $this->askAccount('Your Slack user ID (find it in your Slack profile)', $user->getAuthIdentifier(), 'slack', placeholder: 'U1234567890');

        $this->saveEnv([
            'LARACLAW_SLACK_BOT_TOKEN' => $botToken,
            'LARACLAW_SLACK_SIGNING_SECRET' => $signingSecret,
            'LARACLAW_SLACK_BOT_USER_ID' => $botUserId,
            'LARACLAW_SLACK_ENABLED' => 'true',
        ]);

        UserAccount::updateOrCreate(
            ['user_id' => $user->getAuthIdentifier(), 'channel' => 'slack'],
            ['account' => $ownerSlackId],
        );
    }

    private function setupEmail(mixed $user): void
    {
        info("✨ Let's configure my email.");

        info("First, let's set up IMAP so I can read emails:");

        $imapHost = $this->askEnv('IMAP host', 'LARACLAW_IMAP_HOST', placeholder: 'imap.gmail.com');
        $imapPort = $this->askEnv('IMAP port', 'LARACLAW_IMAP_PORT', input: fn () => text('IMAP port', placeholder: '993', default: '993', required: true));
        $imapEncryption = $this->askEnv('IMAP encryption', 'LARACLAW_IMAP_ENCRYPTION', input: fn () => text('IMAP encryption', placeholder: 'ssl', default: 'ssl', required: true));
        $imapUsername = $this->askEnv('IMAP username', 'LARACLAW_IMAP_USERNAME', placeholder: 'you@example.com');
        $imapPassword = $this->askEnv('IMAP password', 'LARACLAW_IMAP_PASSWORD', secret: true);

        $ownerEmails = $this->askOwnerEmails($user);

        info("Now, let's set up SMTP so I can send emails:");

        $smtpHost = $this->askEnv('SMTP host', 'LARACLAW_SMTP_HOST', placeholder: 'smtp.gmail.com');
        $smtpPort = $this->askEnv('SMTP port', 'LARACLAW_SMTP_PORT', input: fn () => text('SMTP port', placeholder: '587', default: '587', required: true));
        $smtpEncryption = $this->askEnv('SMTP encryption', 'LARACLAW_SMTP_ENCRYPTION', input: fn () => text('SMTP encryption', placeholder: 'tls', default: 'tls', required: true));
        $smtpUsername = $this->askEnv('SMTP username', 'LARACLAW_SMTP_USERNAME', input: fn () => text('SMTP username', placeholder: 'you@example.com', default: $imapUsername, required: true));
        $smtpPassword = $this->askEnv('SMTP password', 'LARACLAW_SMTP_PASSWORD', secret: true);
        $fromAddress = $this->askEnv('From address', 'LARACLAW_SMTP_FROM_ADDRESS', input: fn () => text('From address', placeholder: 'you@example.com', default: $smtpUsername, required: true));
        $fromName = $this->askEnv('From name', 'LARACLAW_SMTP_FROM_NAME', input: fn () => text('From name', default: $this->readEnv('LARACLAW_AGENT_NAME') ?? $user->name, required: true));

        $this->saveEnv([
            'LARACLAW_EMAIL_ENABLED' => 'true',
            'LARACLAW_SMTP_HOST' => $smtpHost,
            'LARACLAW_SMTP_PORT' => $smtpPort,
            'LARACLAW_SMTP_ENCRYPTION' => $smtpEncryption,
            'LARACLAW_SMTP_USERNAME' => $smtpUsername,
            'LARACLAW_SMTP_PASSWORD' => $smtpPassword,
            'LARACLAW_SMTP_FROM_ADDRESS' => $fromAddress,
            'LARACLAW_SMTP_FROM_NAME' => $fromName,
            'LARACLAW_IMAP_HOST' => $imapHost,
            'LARACLAW_IMAP_PORT' => $imapPort,
            'LARACLAW_IMAP_ENCRYPTION' => $imapEncryption,
            'LARACLAW_IMAP_USERNAME' => $imapUsername,
            'LARACLAW_IMAP_PASSWORD' => $imapPassword,
        ]);

        UserAccount::where('user_id', $user->getAuthIdentifier())->where('channel', 'email')->delete();

        $ownerEmails->each(fn ($email) => UserAccount::create([
            'user_id' => $user->getAuthIdentifier(),
            'channel' => 'email',
            'account' => $email,
        ]));
    }

    /**
     * Ask which email addresses the bot should accept messages from, defaulting to existing records or the user's own email.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function askOwnerEmails(mixed $user): \Illuminate\Support\Collection
    {
        info("By default, I will only reply to: {$user->email}.");
        info('If you like, we can add other email addresses I should reply to.');

        if (! confirm('Add other email addresses?', default: false)) {
            return collect([$user->email]);
        }

        return collect(
            explode(',', text(
                label: 'Which email addresses should I reply to? (comma-separated)',
                default: $user->email,
                required: true,
                validate: fn (string $value) => collect(explode(',', $value))
                    ->map(fn ($e) => trim($e))
                    ->filter()
                    ->contains(fn ($e) => ! filter_var($e, FILTER_VALIDATE_EMAIL))
                    ? 'Please enter valid email addresses separated by commas.'
                    : null,
            ))
        )->map(fn ($e) => trim($e))->filter();
    }

    private function selectTools(): void
    {
        $this->heading('🧰 Tools');

        $builtIn = implode(PHP_EOL, [
            '  💬  Answer your questions',
            '  🗣️  Send audio messages',
            '  🌐  Search and browse the web',
            '  🖼️  Resize, convert, and compress images',
            '  ⏰  Schedule one-off reminders',
            '  🔁  Send recurring scheduled messages',
        ]);
        info('Here is what I can do out of the box:' . PHP_EOL . PHP_EOL . $builtIn);
        info('You can also enable these optional tools:');

        $defaults = collect([
            'files' => $this->readEnv('LARACLAW_ALLOWED_DISKS'),
            'calendar' => $this->readEnv('LARACLAW_CALENDAR_DRIVER'),
        ])->filter()->keys()->all();

        $tools = multiselect(
            label: 'Which optional tools do you want to enable?',
            options: ['files' => 'File Manager', 'calendar' => 'Calendar Manager'],
            default: $defaults,
            required: false,
        );

        if (in_array('files', $tools)) {
            $this->setupFileManager();
        }

        if (in_array('calendar', $tools)) {
            $this->setupCalendar();
        }
    }

    private function setupFileManager(): void
    {
        info("✨ Let's configure the File Manager.");
        info("I can only access disks you explicitly allow. Select from your app's configured disks, or create a new local one.");

        $existingDisks = array_keys(config('filesystems.disks', []));
        $currentAllowed = array_filter(explode(',', $this->readEnv('LARACLAW_ALLOWED_DISKS') ?? ''));

        $selected = multiselect(
            label: 'Which disks should I have access to?',
            options: collect($existingDisks)->mapWithKeys(fn ($d) => [$d => $d])->put('_new', '+ Create a new local disk')->all(),
            default: $currentAllowed ?: ['local'],
            required: true,
        );

        if (in_array('_new', $selected)) {
            $selected = array_values(array_filter($selected, fn ($d) => $d !== '_new'));
            $selected[] = $this->createLocalDisk();
        }

        $this->writeEnv('LARACLAW_ALLOWED_DISKS', implode(',', $selected));
    }

    /**
     * Prompt for a new local disk name and root path, then inject the disk into config/filesystems.php.
     */
    private function createLocalDisk(): string
    {
        $name = text(
            label: 'New disk name',
            placeholder: 'E.g. files',
            required: true,
            validate: fn (string $v) => preg_match('/^[a-z][a-z0-9_]*$/', $v)
                ? null
                : 'Use lowercase letters, digits, and underscores only.',
        );

        $root = text(
            label: 'Root path (relative to storage/app)',
            placeholder: "E.g. {$name}",
            default: $name,
            required: true,
        );

        $entry = <<<PHP

        '{$name}' => [
            'driver' => 'local',
            'root'   => storage_path('app/{$root}'),
            'throw'  => false,
        ],
PHP;

        $path = config_path('filesystems.php');
        $content = preg_replace(
            '/([\'"]disks[\'"]\s*=>\s*\[)/',
            '$1' . $entry,
            file_get_contents($path),
        );

        file_put_contents($path, $content);

        info("Disk '{$name}' added to config/filesystems.php.");

        return $name;
    }

    private function setupCalendar(): void
    {
        info("✨ Let's configure the Calendar Manager.");

        $driver = select(
            label: 'Calendar driver',
            options: ['google' => 'Google Calendar', 'apple' => 'Apple CalDAV'],
            default: $this->readEnv('LARACLAW_CALENDAR_DRIVER') ?? 'google',
        );

        match ($driver) {
            'google' => $this->setupGoogleCalendar(),
            'apple' => $this->setupAppleCalendar(),
        };
    }

    private function setupGoogleCalendar(): void
    {
        $calendarId = $this->askEnv('Google Calendar ID (normally, your email address)', 'LARACLAW_GOOGLE_CALENDAR_ID', placeholder: 'you@gmail.com');

        $steps = implode(PHP_EOL, [
            '  1. Go to https://console.cloud.google.com and select or create a project.',
            '  2. Enable the Google Calendar API under APIs & Services.',
            '  3. Go to APIs & Services → Credentials.',
            '  4. Click Create Credentials → OAuth client ID, choose Desktop app.',
            '  5. Download the JSON file to the root of your app and rename it to `oauth-credentials.json`.',
        ]);
        $credentialsPath = base_path('oauth-credentials.json');

        info('To connect Google Calendar, you need an OAuth credentials file:' . PHP_EOL . PHP_EOL . $steps);

        info("Save the file to: {$credentialsPath}");

        spin(function () use ($credentialsPath) {
            while (! file_exists($credentialsPath)) {
                sleep(1);
            }
        }, 'Waiting for oauth-credentials.json…');

        info('Great! The credentials file was found.');

        $this->saveEnv([
            'LARACLAW_CALENDAR_DRIVER' => 'google',
            'LARACLAW_GOOGLE_CALENDAR_ID' => $calendarId,
            'LARACLAW_GOOGLE_CREDENTIALS_JSON' => $credentialsPath,
            'LARACLAW_GOOGLE_TOKEN_JSON' => base_path('oauth-token.json'),
        ]);

        $this->call('laraclaw:google-calendar-auth');
    }

    private function setupAppleCalendar(): void
    {
        $server = $this->askEnv('CalDAV server URL', 'LARACLAW_APPLE_CALDAV_SERVER', input: fn () => text('CalDAV server URL', default: 'https://caldav.icloud.com', required: true));
        $username = $this->askEnv('CalDAV username', 'LARACLAW_APPLE_CALDAV_USERNAME');
        $password = $this->askEnv('CalDAV password', 'LARACLAW_APPLE_CALDAV_PASSWORD', secret: true);
        $calendar = $this->askEnv('Calendar name', 'LARACLAW_APPLE_CALDAV_CALENDAR');

        $this->saveEnv([
            'LARACLAW_CALENDAR_DRIVER' => 'apple',
            'LARACLAW_APPLE_CALDAV_SERVER' => $server,
            'LARACLAW_APPLE_CALDAV_USERNAME' => $username,
            'LARACLAW_APPLE_CALDAV_PASSWORD' => $password,
            'LARACLAW_APPLE_CALDAV_CALENDAR' => $calendar,
        ]);
    }

    private function finish(array $channels): void
    {
        $appUrl = config('app.url', 'https://your-app.com');

        $webhooks = collect([
            'telegram' => "  - Telegram: register your webhook at {$appUrl}/telegram/webhook",
            'slack' => "  - Slack: set your event subscription URL to {$appUrl}/slack/webhook",
        ])->only($channels)->values()->implode(PHP_EOL);

        $message = 'Setup complete!';

        if ($webhooks) {
            $message .= PHP_EOL . PHP_EOL . 'Next steps:' . PHP_EOL . $webhooks;
        }

        info($message);
    }

    private function heading(string $text): void
    {
        echo PHP_EOL . "\033[1m{$text}\033[0m" . PHP_EOL . PHP_EOL;
    }

    /**
     * If the env key already has a value, prompt to use it or set a new one.
     * Secrets are masked in the display.
     */
    private function askEnv(string $label, string $key, bool $secret = false, ?Closure $input = null, string $placeholder = ''): string
    {
        $existing = $this->readEnv($key);

        if ($existing !== null) {
            $display = $secret ? '(hidden)' : $existing;

            if (select($label, ['existing' => "Use existing: {$display}", 'new' => 'Set new value']) === 'existing') {
                return $existing;
            }
        }

        return $input
            ? $input()
            : ($secret
                ? password($label, required: true)
                : text($label, placeholder: $placeholder, required: true));
    }

    /**
     * If a UserAccount already exists for this user/channel, prompt to use it or set a new one.
     */
    private function askAccount(string $label, int|string $userId, string $channel, string $placeholder = ''): string
    {
        $existing = UserAccount::where('user_id', $userId)->where('channel', $channel)->value('account');

        if ($existing !== null && select($label, [
            'existing' => "Use existing: {$existing}",
            'new' => 'Set new value',
        ]) === 'existing') {
            return $existing;
        }

        return text(
            label: $label,
            placeholder: $placeholder,
            required: true,
            validate: fn (string $value) => UserAccount::where('channel', $channel)
                ->where('account', $value)
                ->where('user_id', '!=', $userId)
                ->exists()
                ? 'That account is already registered to another user.'
                : null,
        );
    }

    private function readEnv(string $key): ?string
    {
        $escaped = preg_quote($key, '/');

        if (preg_match('/^' . $escaped . '="?([^"\n]*)"?/m', file_get_contents(base_path('.env')), $m)) {
            return filled($m[1]) ? trim($m[1]) : null;
        }

        return null;
    }

    private function saveEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->writeEnv($key, $value);
        }
    }

    private function writeEnv(string $key, string|int $value): void
    {
        $path = base_path('.env');
        $line = str_contains((string) $value, ' ') ? "{$key}=\"{$value}\"" : "{$key}={$value}";
        $escaped = preg_quote($key, '/');
        $content = file_get_contents($path);

        $content = preg_match("/^{$escaped}=/m", $content)
            ? preg_replace("/^{$escaped}=.*/m", $line, $content)
            : $content . "\n{$line}";

        file_put_contents($path, $content);
    }
}
