<?php

namespace LaraClaw\Console\Commands;

use Illuminate\Console\Command;
use LaraClaw\Console\Concerns\ConfiguresEnv;

use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\spin;

/**
 * Interactive Artisan wizard that provisions the owner account and configures channels.
 */
class SetupWizard extends Command
{
    use ConfiguresEnv;

    protected $signature = 'laraclaw:setup';

    protected $description = 'Interactive setup wizard for LaraClaw';

    public function handle(): int
    {
        echo file_get_contents(__DIR__ . '/../../../resources/ascii/logo.md');

        spin(fn () => $this->callSilently('migrate', ['--force' => true]), 'Running migrations…');

        info('Welcome! LaraClaw is an AI-assistant that runs in your Laravel app.');

        $this->call('laraclaw:setup-admin');
        $this->call('laraclaw:setup-agent');

        $channels = $this->selectChannels();

        foreach ($channels as $channel) {
            $this->call('laraclaw:setup-channel', ['channel' => $channel]);
        }

        $this->selectTools();
        $this->finish($channels);

        return self::SUCCESS;
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
            $this->call('laraclaw:setup-files');
        }

        if (in_array('calendar', $tools)) {
            $this->call('laraclaw:setup-calendar');
        }
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
}
