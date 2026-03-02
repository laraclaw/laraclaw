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

    /**
     * Run the step-by-step setup: migrate, create/select owner, configure channels and tools.
     */
    public function handle(): int
    {
        $this->info('Welcome to the LaraClaw setup wizard.');

        // 1. Run migrations
        spin(fn () => $this->call('migrate', ['--force' => true]), 'Running migrations…');

        // 2. Owner account
        $this->newLine();
        $this->info('Owner account:');

        $userModel = config('laraclaw.auth.user_model');
        $existingUserId = $this->currentEnvValue('LARACLAW_ADMIN_USER_ID');

        if ($existingUserId) {
            $choice = select(
                label: 'Admin user',
                options: [
                    'existing' => "Use existing (ID: {$existingUserId})",
                    'new' => 'Create new user',
                ],
            );

            if ($choice === 'existing') {
                $user = $userModel::find($existingUserId);

                if (! $user) {
                    $this->error("User ID {$existingUserId} not found in the database.");

                    return self::FAILURE;
                }
            } else {
                $user = $this->createUser($userModel);
                $this->writeEnv('LARACLAW_ADMIN_USER_ID', $user->id);
            }
        } else {
            $user = $this->createUser($userModel);
            $this->writeEnv('LARACLAW_ADMIN_USER_ID', $user->id);
        }

        $this->info("Owner: {$user->name} (ID: {$user->id}).");

        // 3. Channel selection — pre-select channels that already have config
        $this->newLine();
        $channelDefaults = array_values(array_filter([
            $this->currentEnvValue('LARACLAW_TELEGRAM_TOKEN') ? 'telegram' : null,
            $this->currentEnvValue('LARACLAW_SLACK_BOT_TOKEN') ? 'slack' : null,
            $this->currentEnvValue('LARACLAW_SMTP_HOST') ? 'email' : null,
        ]));

        $channels = multiselect(
            label: 'Which channels do you want to configure?',
            options: [
                'telegram' => 'Telegram',
                'slack' => 'Slack',
                'email' => 'Email',
            ],
            default: $channelDefaults,
            required: false,
        );

        $configured = [];

        // 4. Per-channel config
        if (in_array('telegram', $channels)) {
            $this->newLine();
            $this->info('Telegram configuration:');

            $token = $this->askEnv('Bot token (from BotFather)', 'LARACLAW_TELEGRAM_TOKEN', secret: true);
            $chatId = $this->askAccount(
                label: 'Your Telegram chat ID (send /start to @userinfobot to get it)',
                userId: $user->getAuthIdentifier(),
                channel: 'telegram',
            );

            $this->writeEnv('LARACLAW_TELEGRAM_TOKEN', $token);
            $this->writeEnv('LARACLAW_TELEGRAM_ENABLED', 'true');
            UserAccount::updateOrCreate(
                ['user_id' => $user->getAuthIdentifier(), 'channel' => 'telegram'],
                ['account' => $chatId],
            );
            $configured[] = 'Telegram';
        }

        if (in_array('slack', $channels)) {
            $this->newLine();
            $this->info('Slack configuration:');

            $botToken = $this->askEnv('Bot token (xoxb-…)', 'LARACLAW_SLACK_BOT_TOKEN', secret: true);
            $signingSecret = $this->askEnv('Signing secret', 'LARACLAW_SLACK_SIGNING_SECRET', secret: true);
            $botUserId = $this->askEnv('Bot user ID (U…)', 'LARACLAW_SLACK_BOT_USER_ID');
            $ownerSlackId = $this->askAccount(
                label: 'Your Slack user ID (U… — find it in your Slack profile)',
                userId: $user->getAuthIdentifier(),
                channel: 'slack',
            );

            $this->writeEnv('LARACLAW_SLACK_BOT_TOKEN', $botToken);
            $this->writeEnv('LARACLAW_SLACK_SIGNING_SECRET', $signingSecret);
            $this->writeEnv('LARACLAW_SLACK_BOT_USER_ID', $botUserId);
            $this->writeEnv('LARACLAW_SLACK_ENABLED', 'true');
            UserAccount::updateOrCreate(
                ['user_id' => $user->getAuthIdentifier(), 'channel' => 'slack'],
                ['account' => $ownerSlackId],
            );
            $configured[] = 'Slack';
        }

        if (in_array('email', $channels)) {
            $this->newLine();
            $this->info('Email — SMTP configuration:');

            $smtpHost = $this->askEnv('SMTP host', 'LARACLAW_SMTP_HOST');
            $smtpPort = $this->askEnv('SMTP port', 'LARACLAW_SMTP_PORT', input: fn () => text(label: 'SMTP port', default: '587', required: true));
            $smtpEncryption = $this->askEnv('SMTP encryption', 'LARACLAW_SMTP_ENCRYPTION', input: fn () => text(label: 'SMTP encryption', default: 'tls', required: true));
            $smtpUsername = $this->askEnv('SMTP username', 'LARACLAW_SMTP_USERNAME');
            $smtpPassword = $this->askEnv('SMTP password', 'LARACLAW_SMTP_PASSWORD', secret: true);
            $fromAddress = $this->askEnv('From address', 'LARACLAW_SMTP_FROM_ADDRESS', input: fn () => text(label: 'From address', default: $smtpUsername, required: true));
            $fromName = $this->askEnv('From name', 'LARACLAW_SMTP_FROM_NAME', input: fn () => text(label: 'From name', default: $user->name, required: true));

            $this->newLine();
            $this->info('Email — IMAP configuration:');

            $imapHost = $this->askEnv('IMAP host', 'LARACLAW_IMAP_HOST');
            $imapPort = $this->askEnv('IMAP port', 'LARACLAW_IMAP_PORT', input: fn () => text(label: 'IMAP port', default: '993', required: true));
            $imapEncryption = $this->askEnv('IMAP encryption', 'LARACLAW_IMAP_ENCRYPTION', input: fn () => text(label: 'IMAP encryption', default: 'ssl', required: true));
            $imapUsername = $this->askEnv('IMAP username', 'LARACLAW_IMAP_USERNAME', input: fn () => text(label: 'IMAP username', default: $smtpUsername, required: true));
            $imapPassword = $this->askEnv('IMAP password', 'LARACLAW_IMAP_PASSWORD', secret: true);

            $this->writeEnv('LARACLAW_EMAIL_ENABLED', 'true');
            $this->writeEnv('LARACLAW_SMTP_HOST', $smtpHost);
            $this->writeEnv('LARACLAW_SMTP_PORT', $smtpPort);
            $this->writeEnv('LARACLAW_SMTP_ENCRYPTION', $smtpEncryption);
            $this->writeEnv('LARACLAW_SMTP_USERNAME', $smtpUsername);
            $this->writeEnv('LARACLAW_SMTP_PASSWORD', $smtpPassword);
            $this->writeEnv('LARACLAW_SMTP_FROM_ADDRESS', $fromAddress);
            $this->writeEnv('LARACLAW_SMTP_FROM_NAME', $fromName);
            $this->writeEnv('LARACLAW_IMAP_HOST', $imapHost);
            $this->writeEnv('LARACLAW_IMAP_PORT', $imapPort);
            $this->writeEnv('LARACLAW_IMAP_ENCRYPTION', $imapEncryption);
            $this->writeEnv('LARACLAW_IMAP_USERNAME', $imapUsername);
            $this->writeEnv('LARACLAW_IMAP_PASSWORD', $imapPassword);

            UserAccount::updateOrCreate(
                ['user_id' => $user->getAuthIdentifier(), 'channel' => 'email'],
                ['account' => $smtpUsername],
            );

            $configured[] = 'Email';
        }

        // 5. Optional tools — pre-select calendar if already configured
        $this->newLine();
        $toolDefaults = array_values(array_filter([
            $this->currentEnvValue('LARACLAW_CALENDAR_DRIVER') ? 'calendar' : null,
        ]));

        $tools = multiselect(
            label: 'Which optional tools do you want to enable?',
            options: ['calendar' => 'Calendar Manager'],
            default: $toolDefaults,
            required: false,
        );

        if (in_array('calendar', $tools)) {
            $driverDefault = $this->currentEnvValue('LARACLAW_CALENDAR_DRIVER') ?? 'google';
            $driver = select(
                label: 'Calendar driver',
                options: ['google' => 'Google Calendar', 'apple' => 'Apple CalDAV'],
                default: $driverDefault,
            );

            if ($driver === 'google') {
                $calendarId = $this->askEnv('Google Calendar ID (found in Google Calendar settings)', 'LARACLAW_GOOGLE_CALENDAR_ID');
                $credentialsPath = $this->askEnv('Path to OAuth credentials JSON', 'LARACLAW_GOOGLE_CREDENTIALS_JSON', input: fn () => text(label: 'Path to OAuth credentials JSON', default: base_path('oauth-credentials.json'), required: true));
                $tokenPath = $this->askEnv('Path to OAuth token JSON', 'LARACLAW_GOOGLE_TOKEN_JSON', input: fn () => text(label: 'Path to OAuth token JSON', default: base_path('oauth-token.json'), required: true));

                $this->writeEnv('LARACLAW_CALENDAR_DRIVER', 'google');
                $this->writeEnv('LARACLAW_GOOGLE_CALENDAR_ID', $calendarId);
                $this->writeEnv('LARACLAW_GOOGLE_CREDENTIALS_JSON', $credentialsPath);
                $this->writeEnv('LARACLAW_GOOGLE_TOKEN_JSON', $tokenPath);

                $this->info('Run `php artisan laraclaw:google-calendar-auth` to complete the OAuth flow.');
            } else {
                $server = $this->askEnv('CalDAV server URL', 'LARACLAW_APPLE_CALDAV_SERVER', input: fn () => text(label: 'CalDAV server URL', default: 'https://caldav.icloud.com', required: true));
                $calUsername = $this->askEnv('CalDAV username', 'LARACLAW_APPLE_CALDAV_USERNAME');
                $calPassword = $this->askEnv('CalDAV password', 'LARACLAW_APPLE_CALDAV_PASSWORD', secret: true);
                $calendar = $this->askEnv('Calendar name', 'LARACLAW_APPLE_CALDAV_CALENDAR');

                $this->writeEnv('LARACLAW_CALENDAR_DRIVER', 'apple');
                $this->writeEnv('LARACLAW_APPLE_CALDAV_SERVER', $server);
                $this->writeEnv('LARACLAW_APPLE_CALDAV_USERNAME', $calUsername);
                $this->writeEnv('LARACLAW_APPLE_CALDAV_PASSWORD', $calPassword);
                $this->writeEnv('LARACLAW_APPLE_CALDAV_CALENDAR', $calendar);
            }
        }

        // 6. Outro
        $this->newLine();
        $summary = implode(', ', $configured) ?: 'none';
        $appUrl = config('app.url', 'https://your-app.com');

        $outroLines = ["Setup complete! Channels configured: {$summary}."];

        if (in_array('telegram', $channels)) {
            $outroLines[] = "Telegram: register your webhook at {$appUrl}/telegram/webhook";
        }

        if (in_array('slack', $channels)) {
            $outroLines[] = "Slack: set your event subscription URL to {$appUrl}/slack/webhook";
        }

        outro(implode("\n", $outroLines));

        return self::SUCCESS;
    }

    /**
     * Collect name, email, and password then create and return a new user.
     */
    private function createUser(string $userModel): mixed
    {
        $name = text(label: 'Name', required: true);

        $email = text(
            label: 'Email',
            required: true,
            validate: function (string $value) use ($userModel) {
                if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return 'Please enter a valid email address.';
                }
                if ($userModel::where('email', $value)->exists()) {
                    return 'That email is already taken.';
                }

                return null;
            },
        );

        $pwd = password(label: 'Password', required: true);

        return $userModel::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($pwd),
        ]);
    }

    /**
     * If the env key already has a value, prompt to use it or set a new one.
     * Secrets are masked in the display. Falls back to a text or password prompt.
     */
    private function askEnv(string $label, string $key, bool $secret = false, ?Closure $input = null): string
    {
        $existing = $this->currentEnvValue($key);

        if ($existing !== null) {
            $display = $secret ? '(hidden)' : $existing;
            $choice = select(
                label: $label,
                options: [
                    'existing' => "Use existing: {$display}",
                    'new' => 'Set new value',
                ],
            );

            if ($choice === 'existing') {
                return $existing;
            }
        }

        if ($input) {
            return $input();
        }

        return $secret
            ? password(label: $label, required: true)
            : text(label: $label, required: true);
    }

    /**
     * If a UserAccount already exists for this user/channel, prompt to use it or set a new one.
     */
    private function askAccount(string $label, int|string $userId, string $channel, ?Closure $input = null): string
    {
        $existing = UserAccount::where('user_id', $userId)
            ->where('channel', $channel)
            ->value('account');

        if ($existing !== null) {
            $choice = select(
                label: $label,
                options: [
                    'existing' => "Use existing: {$existing}",
                    'new' => 'Set new value',
                ],
            );

            if ($choice === 'existing') {
                return $existing;
            }
        }

        return $input ? $input() : text(label: $label, required: true);
    }

    /**
     * Read the current value of a key from the .env file, or null if unset or empty.
     */
    private function currentEnvValue(string $key): ?string
    {
        $env = file_get_contents(base_path('.env'));
        $escaped = preg_quote($key, '/');

        if (preg_match('/^' . $escaped . '="?([^"\n]*)"?/m', $env, $matches)) {
            $value = trim($matches[1]);

            return $value !== '' ? $value : null;
        }

        return null;
    }

    /**
     * Set or update a key-value pair in the .env file.
     */
    private function writeEnv(string $key, string|int $value): void
    {
        $path = base_path('.env');
        $content = file_get_contents($path);
        $line = str_contains((string) $value, ' ') ? "{$key}=\"{$value}\"" : "{$key}={$value}";
        $escaped = preg_quote($key, '/');

        $content = preg_match("/^{$escaped}=/m", $content)
            ? preg_replace("/^{$escaped}=.*/m", $line, $content)
            : $content . "\n{$line}";

        file_put_contents($path, $content);
    }
}
