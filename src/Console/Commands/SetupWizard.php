<?php

namespace LaraClaw\Console\Commands;

use Illuminate\Console\Command;
use LaraClaw\Console\Concerns\ConfiguresEnv;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\spin;

/**
 * Interactive Artisan wizard that provisions the owner account and configures connectors.
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

        $connectors = $this->selectConnectors();

        foreach ($connectors as $connector) {
            $this->call('laraclaw:setup-connector', ['connector' => $connector]);
        }

        $apiEnabled = $this->setupApiEndpoint();

        $this->call('laraclaw:setup-memory');

        $this->selectTools();
        $this->selectSuperpowers();
        $this->finish($connectors, $apiEnabled);

        return self::SUCCESS;
    }

    private function selectConnectors(): array
    {
        $this->heading('📫 Connectors');

        $texts = implode(PHP_EOL, [
            '  - Telegram: you need a Telegram bot',
            '  - Slack: you need a Slack bot',
            '  - Email: you need SMTP and IMAP details',
        ]);
        info("Let's setup ways you can message me:" . PHP_EOL . PHP_EOL . $texts);

        $defaults = collect([
            'telegram' => $this->readEnv('LARACLAW_TELEGRAM_TOKEN'),
            'slack' => $this->readEnv('LARACLAW_SLACK_BOT_TOKEN'),
            'email' => $this->readEnv('LARACLAW_SMTP_HOST'),
        ])->filter()->keys()->all();

        return multiselect(
            label: '📫 Which connectors would you like to set up?',
            options: ['telegram' => 'Telegram', 'slack' => 'Slack', 'email' => 'Email'],
            default: $defaults,
            required: false,
        );
    }

    private function setupApiEndpoint(): bool
    {
        $this->heading('🌐 API');

        $apiEnabled = $this->readEnv('LARACLAW_API_ENABLED') === 'true';

        info("Would you like to send me messages via the API? It's very useful for webhooks.");
        info("If you enable the API endpoint, I'll print out a token here. Make sure to copy it!");

        if (! confirm('Enable API endpoint?', default: $apiEnabled)) {
            $this->saveEnv(['LARACLAW_API_ENABLED' => 'false']);

            return false;
        }

        $this->call('laraclaw:setup-connector', ['connector' => 'api']);

        return true;
    }

    private function selectTools(): void
    {
        $this->heading('🧰 Tools');

        $builtIn = implode(PHP_EOL, [
            '  💬 Answer your questions',
            '  🌐 Search and browse the web',
            '  🖼️ Resize, convert, and compress images',
            '  ⏰ Schedule one-off reminders',
            '  🔁 Send recurring scheduled messages',
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

    private function selectSuperpowers(): void
    {
        $this->heading('🦸 Superpowers');

        $powers = implode(PHP_EOL, [
            '  - Execute Bash commands',
            '  - Use Artisan Tinker to interact with your Laravel app',
        ]);
        info('With superpowers, I can:' . PHP_EOL . PHP_EOL . $powers);
        info('But with great power comes great responsibility. Allowing the agent to execute these commands could in some cases result in unintended negative consequences.');

        $defaults = collect([
            'bash' => $this->readEnv('LARACLAW_BASH_ENABLED') === 'true',
            'tinker' => $this->readEnv('LARACLAW_TINKER_ENABLED') === 'true',
        ])->filter()->keys()->all();

        $selected = multiselect(
            label: 'Which superpowers do you want to enable?',
            options: ['bash' => 'Allow the agent to execute Bash commands', 'tinker' => 'Allow the agent to run Artisan Tinker'],
            default: $defaults,
            required: false,
        );

        $this->saveEnv([
            'LARACLAW_BASH_ENABLED' => in_array('bash', $selected) ? 'true' : 'false',
            'LARACLAW_TINKER_ENABLED' => in_array('tinker', $selected) ? 'true' : 'false',
        ]);
    }

    private function finish(array $connectors, bool $apiEnabled): void
    {
        $appUrl = config('app.url', 'https://your-app.com');

        $allConnectors = $apiEnabled ? [...$connectors, 'api'] : $connectors;

        $steps = collect([
            'telegram' => "  - Telegram: set your webhook URL to {$appUrl}/telegram/webhook",
            'slack' => "  - Slack: set your event subscription URL to {$appUrl}/slack/webhook",
            'api' => "  - API: send POST requests to {$appUrl}/api/message",
        ])->only($allConnectors)->values()->implode(PHP_EOL);

        $message = 'Setup complete!';

        if ($steps) {
            $message .= PHP_EOL . PHP_EOL . 'Next steps:' . PHP_EOL . $steps;
        }

        info($message);
    }
}
