<?php

namespace LaraClaw\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use LaraClaw\Console\Concerns\ConfiguresEnv;
use LaraClaw\Models\UserAccount;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\text;

/**
 * Configure a single LaraClaw channel: telegram, slack, or email.
 */
class SetupChannel extends Command
{
    use ConfiguresEnv;

    protected $signature = 'laraclaw:setup-channel {channel : The channel to configure (telegram, slack, email)}';

    protected $description = 'Configure a LaraClaw channel';

    public function handle(): int
    {
        $channel = $this->argument('channel');

        $userModel = config('laraclaw.auth.user_model');
        $user = $userModel::find($this->readEnv('LARACLAW_ADMIN_USER_ID'));

        if (! $user) {
            $this->error('No admin user found. Run laraclaw:setup-admin first.');

            return self::FAILURE;
        }

        match ($channel) {
            'telegram' => $this->setupTelegram($user),
            'slack' => $this->setupSlack($user),
            'email' => $this->setupEmail($user),
            default => $this->error("Unknown channel '{$channel}'. Valid options: telegram, slack, email."),
        };

        return self::SUCCESS;
    }

    private function setupTelegram(mixed $user): void
    {
        $this->heading('✨ Telegram');

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
        $this->heading('✨ Slack');

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
        $this->heading('✨ Email');

        info("First, let's set up IMAP so I can read emails:");

        $imapHost = $this->askEnv('IMAP host', 'LARACLAW_IMAP_HOST', placeholder: 'imap.gmail.com');
        $imapPort = $this->askEnv('IMAP port', 'LARACLAW_IMAP_PORT', input: fn (): string => text('IMAP port', placeholder: '993', default: '993', required: true));
        $imapEncryption = $this->askEnv('IMAP encryption', 'LARACLAW_IMAP_ENCRYPTION', input: fn (): string => text('IMAP encryption', placeholder: 'ssl', default: 'ssl', required: true));
        $imapUsername = $this->askEnv('IMAP username (email address)', 'LARACLAW_IMAP_USERNAME', placeholder: 'you@example.com');
        $imapPassword = $this->askEnv('IMAP password', 'LARACLAW_IMAP_PASSWORD', secret: true);

        $ownerEmails = $this->askOwnerEmails($user);

        info("Now, let's set up SMTP so I can send emails:");

        $smtpHost = $this->askEnv('SMTP host', 'LARACLAW_SMTP_HOST', placeholder: 'smtp.gmail.com');
        $smtpPort = $this->askEnv('SMTP port', 'LARACLAW_SMTP_PORT', input: fn (): string => text('SMTP port', placeholder: '587', default: '587', required: true));
        $smtpEncryption = $this->askEnv('SMTP encryption', 'LARACLAW_SMTP_ENCRYPTION', input: fn (): string => text('SMTP encryption', placeholder: 'tls', default: 'tls', required: true));
        $smtpUsername = $this->askEnv('SMTP username', 'LARACLAW_SMTP_USERNAME', input: fn (): string => text('SMTP username', placeholder: 'you@example.com', default: $imapUsername, required: true));
        $smtpPassword = $this->askEnv('SMTP password', 'LARACLAW_SMTP_PASSWORD', secret: true);

        $this->saveEnv([
            'LARACLAW_EMAIL_ENABLED' => 'true',
            'LARACLAW_SMTP_HOST' => $smtpHost,
            'LARACLAW_SMTP_PORT' => $smtpPort,
            'LARACLAW_SMTP_ENCRYPTION' => $smtpEncryption,
            'LARACLAW_SMTP_USERNAME' => $smtpUsername,
            'LARACLAW_SMTP_PASSWORD' => $smtpPassword,
            'LARACLAW_SMTP_FROM_ADDRESS' => $smtpUsername,
            'LARACLAW_SMTP_FROM_NAME' => $this->readEnv('LARACLAW_AGENT_NAME') ?? $user->name,
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
     * @return Collection<int, string>
     */
    private function askOwnerEmails(mixed $user): Collection
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
                validate: fn (string $value): ?string => collect(explode(',', $value))
                    ->map(fn ($e): string => trim($e))
                    ->filter()
                    ->contains(fn ($e): bool => ! filter_var($e, FILTER_VALIDATE_EMAIL))
                    ? 'Please enter valid email addresses separated by commas.'
                    : null,
            ))
        )->map(fn ($e): string => trim($e))->filter();
    }
}
