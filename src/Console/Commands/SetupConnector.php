<?php

namespace Laraclaw\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laraclaw\Console\Concerns\ConfiguresEnv;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Account;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\text;

/**
 * Configure a single Laraclaw connector: telegram, slack, email, or api.
 */
class SetupConnector extends Command
{
    use ConfiguresEnv;

    protected $signature = 'laraclaw:setup-connector {connector : The connector to configure (telegram, slack, email, api)}';

    protected $description = 'Configure a Laraclaw connector';

    /**
     * Dispatch to the per-connector setup flow named by the command argument.
     */
    public function handle(): int
    {
        $connector = $this->argument('connector');

        $userModel = config('laraclaw.auth.user_model');
        $user = $userModel::find($this->readEnv('LARACLAW_ADMIN_USER_ID'));

        if (! $user) {
            $this->error('No admin user found. Run laraclaw:setup-admin first.');

            return self::FAILURE;
        }

        $handled = match ($connector) {
            'telegram' => $this->setupTelegram($user) ?? true,
            'slack' => $this->setupSlack($user) ?? true,
            'email' => $this->setupEmail($user) ?? true,
            'api' => $this->setupApi($user) ?? true,
            default => false,
        };

        if (! $handled) {
            $this->error("Unknown connector '{$connector}'. Valid options: telegram, slack, email, api.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Capture the bot token and chat ID for the Telegram connector and bind the owner's account.
     */
    private function setupTelegram(mixed $user): void
    {
        $this->heading('✨ Telegram');

        $token = $this->askEnv('Bot token (from BotFather)', 'LARACLAW_TELEGRAM_TOKEN', secret: true, placeholder: '1234567890:ABCdef…');
        $chatId = $this->askAccount('Your Telegram chat ID (send /start to @userinfobot to get it)', $user->getAuthIdentifier(), 'telegram', placeholder: '123456789');

        // Telegram sends this back on every webhook call, and it is the only thing
        // that tells a real update apart from anyone who guessed the endpoint.
        $secretToken = $this->readEnv('LARACLAW_TELEGRAM_SECRET_TOKEN') ?? Str::random(64);

        $this->saveEnv([
            'LARACLAW_TELEGRAM_TOKEN' => $token,
            'LARACLAW_TELEGRAM_SECRET_TOKEN' => $secretToken,
            'LARACLAW_TELEGRAM_ENABLED' => 'true',
        ]);

        Account::updateOrCreate(
            ['user_id' => $user->getAuthIdentifier(), 'connector' => 'telegram'],
            ['account' => $chatId],
        );

        $this->registerTelegramWebhook($token, $secretToken);
    }

    /**
     * Point the bot at this app's webhook URL, handing Telegram the secret to echo back.
     *
     * The secret only guards the endpoint once Telegram knows about it, so an
     * install that skips this step will see its own updates rejected.
     */
    private function registerTelegramWebhook(string $token, string $secretToken): void
    {
        $url = text(
            label: 'Public webhook URL',
            placeholder: 'https://your-app.com/telegram/webhook',
            default: url('/telegram/webhook'),
            required: true,
        );

        if (! confirm("Register {$url} with Telegram now?")) {
            info('Skipped. Call setWebhook yourself with secret_token set to LARACLAW_TELEGRAM_SECRET_TOKEN, or Telegram updates will be rejected.');

            return;
        }

        try {
            new Api($token)->setWebhook(['url' => $url, 'secret_token' => $secretToken]);

            info('Webhook registered.');
        } catch (TelegramSDKException $e) {
            $this->error('Could not register the webhook: ' . $e->getMessage());
            $this->warn('Register it yourself with secret_token set to LARACLAW_TELEGRAM_SECRET_TOKEN.');
        }
    }

    /**
     * Capture Slack tokens and bind the owner's Slack user ID to their account.
     */
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

        Account::updateOrCreate(
            ['user_id' => $user->getAuthIdentifier(), 'connector' => 'slack'],
            ['account' => $ownerSlackId],
        );
    }

    /**
     * Collect IMAP and SMTP settings, then register the owner's allowlisted email addresses.
     */
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

        Account::where('user_id', $user->getAuthIdentifier())->where('connector', 'email')->delete();

        $ownerEmails->each(fn ($email) => Account::create([
            'user_id' => $user->getAuthIdentifier(),
            'connector' => 'email',
            'account' => $email,
        ]));
    }

    /**
     * Generate or rotate the owner's API bearer token and store its hash in the accounts table.
     */
    private function setupApi(mixed $user): void
    {
        $existing = Account::where('user_id', $user->getAuthIdentifier())
            ->where('connector', ConnectorType::Api)
            ->exists();

        if (! $existing || confirm('An API token already exists. Generate a new one? This will replace the current token.', default: false)) {
            $plaintext = Str::random(64);

            Account::updateOrCreate(
                ['user_id' => $user->getAuthIdentifier(), 'connector' => ConnectorType::Api],
                ['account' => hash('sha256', $plaintext)],
            );

            info('Your API token (save this somewhere safe, it will not be shown again):');
            info($plaintext);
        }

        $this->saveEnv(['LARACLAW_API_ENABLED' => 'true']);
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
