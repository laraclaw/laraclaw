<?php

namespace LaraClaw\Console\Commands;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use LaraClaw\Models\UserAccount;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\outro;
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
        $this->info('Welcome to the LaraClaw setup wizard.');

        spin(fn () => $this->call('migrate', ['--force' => true]), 'Running migrations…');

        $user = $this->resolveOwner();

        if (! $user) {
            return self::FAILURE;
        }

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
        $this->newLine();
        $this->info('Owner account:');

        $userModel = config('laraclaw.auth.user_model');
        $existingId = $this->readEnv('LARACLAW_ADMIN_USER_ID');

        if ($existingId && select('Admin user', [
            'existing' => "Use existing (ID: {$existingId})",
            'new' => 'Create new user',
        ]) === 'existing') {
            $user = $userModel::find($existingId);

            if (! $user) {
                $this->error("User ID {$existingId} not found in the database.");

                return null;
            }

            $this->info("Owner: {$user->name} (ID: {$user->id}).");

            return $user;
        }

        $user = $this->createUser($userModel);
        $this->writeEnv('LARACLAW_ADMIN_USER_ID', $user->id);
        $this->info("Owner: {$user->name} (ID: {$user->id}).");

        return $user;
    }

    private function createUser(string $userModel): mixed
    {
        $name = text('Name', required: true);

        $email = text(
            label: 'Email',
            required: true,
            validate: fn (string $value) => match (true) {
                ! filter_var($value, FILTER_VALIDATE_EMAIL) => 'Please enter a valid email address.',
                $userModel::where('email', $value)->exists() => 'That email is already taken.',
                default => null,
            },
        );

        return $userModel::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(password('Password', required: true)),
        ]);
    }

    private function selectChannels(): array
    {
        $this->newLine();

        $defaults = collect([
            'telegram' => $this->readEnv('LARACLAW_TELEGRAM_TOKEN'),
            'slack' => $this->readEnv('LARACLAW_SLACK_BOT_TOKEN'),
            'email' => $this->readEnv('LARACLAW_SMTP_HOST'),
        ])->filter()->keys()->all();

        return multiselect(
            label: 'Which channels do you want to configure?',
            options: ['telegram' => 'Telegram', 'slack' => 'Slack', 'email' => 'Email'],
            default: $defaults,
            required: false,
        );
    }

    private function setupTelegram(mixed $user): void
    {
        $this->newLine();
        $this->info('Telegram configuration:');

        $token = $this->askEnv('Bot token (from BotFather)', 'LARACLAW_TELEGRAM_TOKEN', secret: true);
        $chatId = $this->askAccount('Your Telegram chat ID (send /start to @userinfobot to get it)', $user->getAuthIdentifier(), 'telegram');

        $this->saveEnv(['LARACLAW_TELEGRAM_TOKEN' => $token, 'LARACLAW_TELEGRAM_ENABLED' => 'true']);

        UserAccount::updateOrCreate(
            ['user_id' => $user->getAuthIdentifier(), 'channel' => 'telegram'],
            ['account' => $chatId],
        );
    }

    private function setupSlack(mixed $user): void
    {
        $this->newLine();
        $this->info('Slack configuration:');

        $botToken = $this->askEnv('Bot token (xoxb-…)', 'LARACLAW_SLACK_BOT_TOKEN', secret: true);
        $signingSecret = $this->askEnv('Signing secret', 'LARACLAW_SLACK_SIGNING_SECRET', secret: true);
        $botUserId = $this->askEnv('Bot user ID (U…)', 'LARACLAW_SLACK_BOT_USER_ID');
        $ownerSlackId = $this->askAccount('Your Slack user ID (U… — find it in your Slack profile)', $user->getAuthIdentifier(), 'slack');

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
        $this->newLine();
        $this->info('Email — SMTP configuration:');

        $smtpHost = $this->askEnv('SMTP host', 'LARACLAW_SMTP_HOST');
        $smtpPort = $this->askEnv('SMTP port', 'LARACLAW_SMTP_PORT', input: fn () => text('SMTP port', default: '587', required: true));
        $smtpEncryption = $this->askEnv('SMTP encryption', 'LARACLAW_SMTP_ENCRYPTION', input: fn () => text('SMTP encryption', default: 'tls', required: true));
        $smtpUsername = $this->askEnv('SMTP username', 'LARACLAW_SMTP_USERNAME');
        $smtpPassword = $this->askEnv('SMTP password', 'LARACLAW_SMTP_PASSWORD', secret: true);
        $fromAddress = $this->askEnv('From address', 'LARACLAW_SMTP_FROM_ADDRESS', input: fn () => text('From address', default: $smtpUsername, required: true));
        $fromName = $this->askEnv('From name', 'LARACLAW_SMTP_FROM_NAME', input: fn () => text('From name', default: $user->name, required: true));

        $this->newLine();
        $this->info('Email — IMAP configuration:');

        $imapHost = $this->askEnv('IMAP host', 'LARACLAW_IMAP_HOST');
        $imapPort = $this->askEnv('IMAP port', 'LARACLAW_IMAP_PORT', input: fn () => text('IMAP port', default: '993', required: true));
        $imapEncryption = $this->askEnv('IMAP encryption', 'LARACLAW_IMAP_ENCRYPTION', input: fn () => text('IMAP encryption', default: 'ssl', required: true));
        $imapUsername = $this->askEnv('IMAP username', 'LARACLAW_IMAP_USERNAME', input: fn () => text('IMAP username', default: $smtpUsername, required: true));
        $imapPassword = $this->askEnv('IMAP password', 'LARACLAW_IMAP_PASSWORD', secret: true);

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

        UserAccount::updateOrCreate(
            ['user_id' => $user->getAuthIdentifier(), 'channel' => 'email'],
            ['account' => $smtpUsername],
        );
    }

    private function selectTools(): void
    {
        $this->newLine();

        $defaults = collect(['calendar' => $this->readEnv('LARACLAW_CALENDAR_DRIVER')])->filter()->keys()->all();

        $tools = multiselect(
            label: 'Which optional tools do you want to enable?',
            options: ['calendar' => 'Calendar Manager'],
            default: $defaults,
            required: false,
        );

        if (in_array('calendar', $tools)) {
            $this->setupCalendar();
        }
    }

    private function setupCalendar(): void
    {
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
        $calendarId = $this->askEnv('Google Calendar ID (found in Google Calendar settings)', 'LARACLAW_GOOGLE_CALENDAR_ID');
        $credentialsPath = $this->askEnv('Path to OAuth credentials JSON', 'LARACLAW_GOOGLE_CREDENTIALS_JSON', input: fn () => text('Path to OAuth credentials JSON', default: base_path('oauth-credentials.json'), required: true));
        $tokenPath = $this->askEnv('Path to OAuth token JSON', 'LARACLAW_GOOGLE_TOKEN_JSON', input: fn () => text('Path to OAuth token JSON', default: base_path('oauth-token.json'), required: true));

        $this->saveEnv([
            'LARACLAW_CALENDAR_DRIVER' => 'google',
            'LARACLAW_GOOGLE_CALENDAR_ID' => $calendarId,
            'LARACLAW_GOOGLE_CREDENTIALS_JSON' => $credentialsPath,
            'LARACLAW_GOOGLE_TOKEN_JSON' => $tokenPath,
        ]);

        $this->info('Run `php artisan laraclaw:google-calendar-auth` to complete the OAuth flow.');
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
        $this->newLine();

        $configured = collect($channels)->map(fn ($c) => ucfirst($c))->join(', ') ?: 'none';
        $appUrl = config('app.url', 'https://your-app.com');

        $lines = ["Setup complete! Channels configured: {$configured}."];

        if (in_array('telegram', $channels)) {
            $lines[] = "Telegram: register your webhook at {$appUrl}/telegram/webhook";
        }

        if (in_array('slack', $channels)) {
            $lines[] = "Slack: set your event subscription URL to {$appUrl}/slack/webhook";
        }

        outro(implode("\n", $lines));
    }

    /**
     * If the env key already has a value, prompt to use it or set a new one.
     * Secrets are masked in the display.
     */
    private function askEnv(string $label, string $key, bool $secret = false, ?Closure $input = null): string
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
            : ($secret ? password($label, required: true) : text($label, required: true));
    }

    /**
     * If a UserAccount already exists for this user/channel, prompt to use it or set a new one.
     */
    private function askAccount(string $label, int|string $userId, string $channel): string
    {
        $existing = UserAccount::where('user_id', $userId)->where('channel', $channel)->value('account');

        if ($existing !== null && select($label, [
            'existing' => "Use existing: {$existing}",
            'new' => 'Set new value',
        ]) === 'existing') {
            return $existing;
        }

        return text($label, required: true);
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
