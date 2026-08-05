<?php

namespace Laraclaw;

use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laraclaw\Commands\CommandRegistry;
use Laraclaw\Commands\NewConversation;
use Laraclaw\Concerns\RegistersReadOnlyDatabase;
use Laraclaw\Console\Commands\Chat;
use Laraclaw\Console\Commands\ConnectorAddCommand;
use Laraclaw\Console\Commands\GoogleCalendarAuth;
use Laraclaw\Console\Commands\ProcessHeartbeats;
use Laraclaw\Console\Commands\SendReminders;
use Laraclaw\Console\Commands\SetupAdmin;
use Laraclaw\Console\Commands\SetupAgent;
use Laraclaw\Console\Commands\SetupCalendar;
use Laraclaw\Console\Commands\SetupConnector;
use Laraclaw\Console\Commands\SetupFiles;
use Laraclaw\Console\Commands\SetupMemory;
use Laraclaw\Console\Commands\SetupReadDatabase;
use Laraclaw\Console\Commands\SetupWizard;
use Laraclaw\Events\TelegramMessageReceived;
use Laraclaw\Http\Middleware\VerifyApiToken;
use Laraclaw\Http\Middleware\VerifySlackSignature;
use Laraclaw\Listeners\EmailListener;
use Laraclaw\Listeners\EmbedConversation;
use Laraclaw\Listeners\LogAgentRequest;
use Laraclaw\Listeners\TelegramListener;
use Laraclaw\Services\Calendar\AppleCalendarDriver;
use Laraclaw\Services\Calendar\Contracts\CalendarDriver;
use Laraclaw\Services\Calendar\GoogleCalendarDriver;
use Laraclaw\Services\Memory\ContentChunker;
use Laraclaw\Services\Memory\EmbedContent;
use Laraclaw\Skills\SkillRegistry;
use Laraclaw\Tools\ToolRegistry;
use Laravel\Ai\Events\AgentPrompted;
use Override;
use RuntimeException;
use Spatie\GoogleCalendar\GoogleCalendar;

/**
 * Registers and boots all Laraclaw services, bindings, routes, and scheduled commands.
 */
class LaraclawServiceProvider extends ServiceProvider
{
    use RegistersReadOnlyDatabase;

    /**
     * Bind services, configure connectors, and register the calendar driver.
     */
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/laraclaw.php', 'laraclaw');

        $this->registerBlockingRedisConnection();
        $this->registerCoreSingletons();
        $this->configureTelegramConnector();
        $this->configureEmailConnector();
        $this->registerCalendarDriver();

        $this->app->singleton(ContentChunker::class);
        $this->app->singleton(EmbedContent::class);
    }

    /**
     * Register routes, migrations, event listeners, Artisan commands, and the scheduler.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            $this->validateConfiguration();
        }

        $this->configureRateLimiting();
        $this->registerReadOnlyDatabaseConnection();

        $this->loadRoutesFrom(__DIR__ . '/../routes/laraclaw.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->app['router']->aliasMiddleware('slack.signature', VerifySlackSignature::class);
        $this->app['router']->aliasMiddleware('laraclaw.api', VerifyApiToken::class);

        $this->publishes([
            __DIR__ . '/../config/laraclaw.php' => config_path('laraclaw.php'),
            __DIR__ . '/../database/migrations' => database_path('migrations'),
            __DIR__ . '/../resources' => resource_path('laraclaw'),
        ], 'laraclaw');

        $this->registerEventListeners();
        $this->registerCommands();
        $this->registerScheduler();
        $this->extendGoogleCalendarToken();
    }

    /**
     * Register named rate limiters for each webhook route, keyed by conversation.
     */
    private function configureRateLimiting(): void
    {
        $perMinute = (int) config('laraclaw.webhook_rate_limit', 20);

        if ($perMinute <= 0) {
            return;
        }

        RateLimiter::for('laraclaw-slack', function (Request $request) use ($perMinute) {
            $event = $request->input('event', []);
            $key = ($event['channel'] ?? 'unknown') . ':' . ($event['user'] ?? 'bot');

            return Limit::perMinute($perMinute)->by('slack:' . $key);
        });

        RateLimiter::for('laraclaw-telegram', function (Request $request) use ($perMinute) {
            $chatId = $request->input('message.chat.id')
                ?? $request->input('channel_post.chat.id')
                ?? 'unknown';

            return Limit::perMinute($perMinute)->by('telegram:' . $chatId);
        });

        RateLimiter::for('laraclaw-api', fn (Request $request) => Limit::perMinute($perMinute)->by('api:' . ($request->user()?->getAuthIdentifier() ?? 'unknown')));
    }

    /**
     * Throw if required configuration values are missing for the enabled connectors.
     */
    private function validateConfiguration(): void
    {
        $needsOwner = config('laraclaw.connectors.telegram.enabled')
            || config('laraclaw.connectors.slack.enabled');

        if ($needsOwner && ! config('laraclaw.auth.admin_user_id')) {
            throw new RuntimeException(
                'Laraclaw: LARACLAW_ADMIN_USER_ID must be set when Telegram or Slack connectors are enabled.'
            );
        }

        if (config('laraclaw.connectors.slack.enabled') && empty(config('laraclaw.connectors.slack.signing_secret'))) {
            throw new RuntimeException(
                'Laraclaw: LARACLAW_SLACK_SIGNING_SECRET must be set when the Slack connector is enabled.'
            );
        }

        if (config('laraclaw.memory.enabled') && ! config('ai.default_for_embeddings')) {
            throw new RuntimeException(
                'Laraclaw: ai.default_for_embeddings must be configured when the memory is enabled. '
                . 'Set AI_DEFAULT_FOR_EMBEDDINGS in your .env (e.g. openai, voyage, ollama).'
            );
        }
    }

    /**
     * Register a dedicated blocking Redis connection used by blpop in the confirmation flow.
     *
     * Setting read_write_timeout to -1 prevents Predis from timing out before blpop returns.
     */
    private function registerBlockingRedisConnection(): void
    {
        $this->app->booting(function (): void {
            $default = config('database.redis.default', []);
            config(['database.redis.laraclaw-blocking' => array_merge($default, ['read_write_timeout' => -1])]);
        });
    }

    /**
     * Bind the CommandRegistry, SkillRegistry, and ToolRegistry singletons.
     */
    private function registerCoreSingletons(): void
    {
        $this->app->singleton(function (): CommandRegistry {
            $registry = new CommandRegistry;
            $registry->register(new NewConversation);

            return $registry;
        });

        $this->app->singleton(fn (): SkillRegistry => new SkillRegistry(config('laraclaw.skills.path', base_path('laraclaw/skills'))));

        $this->app->singleton(ToolRegistry::class, fn (): ToolRegistry => new ToolRegistry);
    }

    /**
     * Push the Telegram token into the telegram-bot-sdk config.
     */
    private function configureTelegramConnector(): void
    {
        $this->app->booting(function (): void {
            config()->set('telegram.bots.laraclaw', [
                'token' => config('laraclaw.connectors.telegram.token'),
            ]);
            config()->set('telegram.default', 'laraclaw');
        });
    }

    /**
     * Copy email SMTP and IMAP credentials from the Laraclaw config into Laravel's mail and IMAP configs.
     */
    private function configureEmailConnector(): void
    {
        // Read the config once the app has booted rather than during registration, so
        // callers that set laraclaw config after this provider is registered still get
        // their SMTP settings honoured instead of silently leaving the mailer on
        // whatever transport the host app defaults to. booted() also fires right away
        // when the app has already booted, which booting() does not.
        $this->app->booted(function (): void {
            if (! config('laraclaw.connectors.email.enabled')) {
                return;
            }

            $smtp = config('laraclaw.connectors.email.smtp');
            if ($smtp['host']) {
                config([
                    'mail.default' => 'smtp',
                    'mail.mailers.smtp.host' => $smtp['host'],
                    'mail.mailers.smtp.port' => $smtp['port'],
                    'mail.mailers.smtp.encryption' => $smtp['encryption'],
                    'mail.mailers.smtp.username' => $smtp['username'],
                    'mail.mailers.smtp.password' => $smtp['password'],
                    'mail.from.address' => $smtp['from_address'],
                    'mail.from.name' => $smtp['from_name'],
                ]);
            }

            $imap = config('laraclaw.connectors.email.imap');
            $mailbox = config('laraclaw.connectors.email.imap.mailbox', 'default');
            if ($imap['host']) {
                config([
                    "imap.mailboxes.{$mailbox}.host" => $imap['host'],
                    "imap.mailboxes.{$mailbox}.port" => $imap['port'],
                    "imap.mailboxes.{$mailbox}.encryption" => $imap['encryption'],
                    "imap.mailboxes.{$mailbox}.username" => $imap['username'],
                    "imap.mailboxes.{$mailbox}.password" => $imap['password'],
                ]);
            }
        });
    }

    /**
     * Configure the Google Calendar package when the Google driver is selected, then bind the CalendarDriver singleton.
     */
    private function registerCalendarDriver(): void
    {
        if (config('laraclaw.tools.calendar_manager.driver') === 'google') {
            config([
                'google-calendar.default_auth_profile' => 'oauth',
                'google-calendar.auth_profiles.oauth.credentials_json' => config('laraclaw.tools.calendar_manager.google.credentials_json'),
                'google-calendar.auth_profiles.oauth.token_json' => config('laraclaw.tools.calendar_manager.google.token_json'),
                'google-calendar.calendar_id' => config('laraclaw.tools.calendar_manager.google.calendar_id'),
            ]);
        }

        $this->app->singleton(fn (): ?CalendarDriver => match (config('laraclaw.tools.calendar_manager.driver')) {
            'google' => new GoogleCalendarDriver,
            'apple' => new AppleCalendarDriver(
                server: config('laraclaw.tools.calendar_manager.apple.server'),
                username: config('laraclaw.tools.calendar_manager.apple.username'),
                password: config('laraclaw.tools.calendar_manager.apple.password'),
                calendar: config('laraclaw.tools.calendar_manager.apple.calendar'),
            ),
            null => null,
            default => throw new RuntimeException('Unknown calendar driver: ' . config('laraclaw.tools.calendar_manager.driver')),
        });
    }

    /**
     * Register event listeners for each enabled connector and for agent request logging.
     */
    private function registerEventListeners(): void
    {
        if (config('laraclaw.connectors.email.enabled')) {
            Event::listen(MessageReceived::class, EmailListener::class);
        }

        if (config('laraclaw.connectors.telegram.enabled')) {
            Event::listen(TelegramMessageReceived::class, TelegramListener::class);
        }

        Event::listen(AgentPrompted::class, EmbedConversation::class);
        Event::listen(AgentPrompted::class, LogAgentRequest::class);
    }

    /**
     * Register all Artisan commands provided by this package.
     */
    private function registerCommands(): void
    {
        $this->commands([
            GoogleCalendarAuth::class,
            ConnectorAddCommand::class,
            SendReminders::class,
            ProcessHeartbeats::class,
            SetupWizard::class,
            SetupAdmin::class,
            SetupAgent::class,
            SetupCalendar::class,
            SetupConnector::class,
            SetupFiles::class,
            SetupMemory::class,
            SetupReadDatabase::class,
            Chat::class,
        ]);
    }

    /**
     * Schedule the reminder and heartbeat commands to run every minute.
     */
    private function registerScheduler(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(SendReminders::class)->everyMinute();
            $schedule->command(ProcessHeartbeats::class)->everyMinute();
        });
    }

    /**
     * Extend the GoogleCalendar binding to refresh an expired OAuth token and persist it to disk.
     */
    private function extendGoogleCalendarToken(): void
    {
        if (config('laraclaw.tools.calendar_manager.driver') !== 'google') {
            return;
        }

        $this->app->extend(GoogleCalendar::class, function (GoogleCalendar $calendar): GoogleCalendar {
            $client = $calendar->getService()->getClient();
            $tokenPath = config('laraclaw.tools.calendar_manager.google.token_json');

            if ($client->isAccessTokenExpired() && $client->getRefreshToken()) {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                $encoded = json_encode($client->getAccessToken());
                if ($encoded !== false) {
                    $written = file_put_contents($tokenPath, $encoded);
                    if ($written === false) {
                        Log::warning('Failed to write Google OAuth token', ['path' => $tokenPath]);
                    }
                }
            }

            return $calendar;
        });
    }
}
