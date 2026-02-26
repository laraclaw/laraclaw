<?php

namespace LaraClaw\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use LaraClaw\Models\UserChannel;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\password;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

class SetupWizard extends Command
{
    protected $signature = 'laraclaw:setup';

    protected $description = 'Interactive setup wizard for LaraClaw';

    public function handle(): int
    {
        // 1. Bail if already set up
        $env = file_get_contents(base_path('.env'));
        if (preg_match('/^LARACLAW_OWNER_ID=.+/m', $env)) {
            $this->error('LaraClaw is already set up (LARACLAW_OWNER_ID is set). Aborting.');
            return self::FAILURE;
        }

        $this->info('Welcome to the LaraClaw setup wizard.');

        // 2. Run migrations
        spin(fn () => $this->call('migrate', ['--force' => true]), 'Running migrations…');

        // 3. Create owner account
        $this->newLine();
        $this->info('Create your owner account:');

        $userModel = config('laraclaw.user_model');

        $name = text(
            label: 'Name',
            required: true,
        );

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

        $pwd = password(
            label: 'Password',
            required: true,
        );

        $user = $userModel::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($pwd),
        ]);

        $this->writeEnv('LARACLAW_OWNER_ID', $user->id);
        $this->info("Owner account created (ID: {$user->id}).");

        // 4. Channel selection
        $this->newLine();
        $channels = multiselect(
            label: 'Which channels do you want to configure?',
            options: [
                'telegram' => 'Telegram',
                'slack' => 'Slack',
                'email' => 'Email',
            ],
            required: false,
        );

        $configured = [];

        // 5. Per-channel config
        if (in_array('telegram', $channels)) {
            $this->newLine();
            $this->info('Telegram configuration:');

            $token = text(label: 'Bot token (from BotFather)', required: true);
            $chatId = text(label: 'Your Telegram chat ID (send /start to @userinfobot to get it)', required: true);
            $this->writeEnv('LARACLAW_TELEGRAM_TOKEN', $token);
            UserChannel::create([
                'user_id' => $user->getAuthIdentifier(),
                'identifier' => "telegram:{$chatId}",
            ]);
            $configured[] = 'Telegram';
        }

        if (in_array('slack', $channels)) {
            $this->newLine();
            $this->info('Slack configuration:');

            $botToken = text(label: 'Bot token (xoxb-…)', required: true);
            $signingSecret = text(label: 'Signing secret', required: true);
            $botUserId = text(label: 'Bot user ID (U…)', required: true);
            $ownerSlackId = text(label: 'Your Slack user ID (U… — find it in your Slack profile)', required: true);

            $this->writeEnv('LARACLAW_SLACK_BOT_TOKEN', $botToken);
            $this->writeEnv('LARACLAW_SLACK_SIGNING_SECRET', $signingSecret);
            $this->writeEnv('LARACLAW_SLACK_BOT_USER_ID', $botUserId);
            UserChannel::create([
                'user_id' => $user->getAuthIdentifier(),
                'identifier' => "slack:{$ownerSlackId}",
            ]);
            $configured[] = 'Slack';
        }

        if (in_array('email', $channels)) {
            $this->newLine();
            $this->info('Email — SMTP configuration:');

            $smtpHost = text(label: 'SMTP host', required: true);
            $smtpPort = text(label: 'SMTP port', default: '587', required: true);
            $smtpEncryption = text(label: 'SMTP encryption', default: 'tls', required: true);
            $smtpUsername = text(label: 'SMTP username', required: true);
            $smtpPassword = password(label: 'SMTP password', required: true);
            $fromAddress = text(label: 'From address', default: $smtpUsername, required: true);
            $fromName = text(label: 'From name', default: $name, required: true);

            $this->newLine();
            $this->info('Email — IMAP configuration:');

            $imapHost = text(label: 'IMAP host', required: true);
            $imapPort = text(label: 'IMAP port', default: '993', required: true);
            $imapEncryption = text(label: 'IMAP encryption', default: 'ssl', required: true);
            $imapUsername = text(label: 'IMAP username', default: $smtpUsername, required: true);
            $imapPassword = password(label: 'IMAP password', required: true);

            $this->writeEnv('LARACLAW_EMAIL_ENABLED', 'true');
            $this->writeEnv('LARACLAW_MAIL_SMTP_HOST', $smtpHost);
            $this->writeEnv('LARACLAW_MAIL_SMTP_PORT', $smtpPort);
            $this->writeEnv('LARACLAW_MAIL_SMTP_ENCRYPTION', $smtpEncryption);
            $this->writeEnv('LARACLAW_MAIL_SMTP_USERNAME', $smtpUsername);
            $this->writeEnv('LARACLAW_MAIL_SMTP_PASSWORD', $smtpPassword);
            $this->writeEnv('LARACLAW_MAIL_FROM_ADDRESS', $fromAddress);
            $this->writeEnv('LARACLAW_MAIL_FROM_NAME', $fromName);
            $this->writeEnv('LARACLAW_IMAP_HOST', $imapHost);
            $this->writeEnv('LARACLAW_IMAP_PORT', $imapPort);
            $this->writeEnv('LARACLAW_IMAP_ENCRYPTION', $imapEncryption);
            $this->writeEnv('LARACLAW_IMAP_USERNAME', $imapUsername);
            $this->writeEnv('LARACLAW_IMAP_PASSWORD', $imapPassword);

            // Auto-register owner in user_channels as email:{smtp_username}
            UserChannel::create([
                'user_id' => $user->getAuthIdentifier(),
                'identifier' => "email:{$smtpUsername}",
            ]);

            $configured[] = 'Email';
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
