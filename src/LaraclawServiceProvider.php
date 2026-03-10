<?php

namespace LaraClaw;

use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LaraClaw\Calendar\AppleCalendarDriver;
use LaraClaw\Calendar\Contracts\CalendarDriver;
use LaraClaw\Calendar\GoogleCalendarDriver;
use LaraClaw\Commands\CommandRegistry;
use LaraClaw\Commands\NewConversation;
use LaraClaw\Console\Commands\ChannelAddCommand;
use LaraClaw\Console\Commands\Chat;
use LaraClaw\Console\Commands\GoogleCalendarAuth;
use LaraClaw\Console\Commands\ProcessHeartbeats;
use LaraClaw\Console\Commands\SendReminders;
use LaraClaw\Console\Commands\SetupAdmin;
use LaraClaw\Console\Commands\SetupAgent;
use LaraClaw\Console\Commands\SetupCalendar;
use LaraClaw\Console\Commands\SetupChannel;
use LaraClaw\Console\Commands\SetupFiles;
use LaraClaw\Console\Commands\SetupWizard;
use LaraClaw\Events\TelegramMessageReceived;
use LaraClaw\Gateway\Prism\CachedPrismGateway;
use LaraClaw\Http\Middleware\VerifySlackSignature;
use LaraClaw\Listeners\EmailListener;
use LaraClaw\Listeners\LogAgentRequest;
use LaraClaw\Listeners\TelegramListener;
use LaraClaw\Skills\SkillRegistry;
use LaraClaw\Tools\ToolRegistry;
use Laravel\Ai\AiManager;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Providers\AnthropicProvider;
use Override;
use RuntimeException;
use Spatie\GoogleCalendar\GoogleCalendar;

/**
 * Registers and boots all LaraClaw services, bindings, routes, and scheduled commands.
 */
class LaraclawServiceProvider extends ServiceProvider
{
    /**
     * Bind services, configure channels, and register the calendar driver.
     */
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/laraclaw.php', 'laraclaw');

        $this->registerBlockingRedisConnection();
        $this->registerCoreSingletons();
        $this->configureTelegramChannel();
        $this->configureEmailChannel();
        $this->registerCalendarDriver();
    }

    /**
     * Register routes, migrations, event listeners, Artisan commands, and the scheduler.
     */
    public function boot(): void
    {
        $this->registerCachedGateway();

        if (! $this->app->runningInConsole()) {
            $this->validateConfiguration();
        }

        $this->configureRateLimiting();

        $this->loadRoutesFrom(__DIR__ . '/../routes/laraclaw.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->app['router']->aliasMiddleware('slack.signature', VerifySlackSignature::class);

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

        RateLimiter::for('laraclaw-api', function (Request $request) use ($perMinute) {
            return Limit::perMinute($perMinute)->by('api:' . ($request->user()?->getAuthIdentifier() ?? 'unknown'));
        });
    }

    /**
     * Throw if required configuration values are missing for the enabled channels.
     */
    private function validateConfiguration(): void
    {
        $needsOwner = config('laraclaw.channels.telegram.enabled')
            || config('laraclaw.channels.slack.enabled');

        if ($needsOwner && ! config('laraclaw.auth.admin_user_id')) {
            throw new RuntimeException(
                'LaraClaw: LARACLAW_ADMIN_USER_ID must be set when Telegram or Slack channels are enabled.'
            );
        }

        if (config('laraclaw.channels.slack.enabled') && empty(config('laraclaw.channels.slack.signing_secret'))) {
            throw new RuntimeException(
                'LaraClaw: LARACLAW_SLACK_SIGNING_SECRET must be set when the Slack channel is enabled.'
            );
        }
    }

    /**
     * Override the Anthropic driver in AiManager to use CachedPrismGateway.
     *
     * Laravel AI hardcodes `new PrismGateway` inside each driver factory method,
     * so the only way to swap in our caching gateway is to re-register AiManager
     * with the Anthropic driver overridden. Other providers are unaffected because
     * prompt caching with cache_control is an Anthropic specific feature.
     */
    private function registerCachedGateway(): void
    {
        $this->app->scoped(AiManager::class, fn ($app): AiManager => new class($app) extends AiManager
        {
            public function createAnthropicDriver(array $config): AnthropicProvider
            {
                return new AnthropicProvider(
                    new CachedPrismGateway($this->app['events']),
                    $config,
                    $this->app->make(Dispatcher::class),
                );
            }
        });
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
    private function configureTelegramChannel(): void
    {
        $this->app->booting(function (): void {
            config()->set('telegram.bots.laraclaw', [
                'token' => config('laraclaw.channels.telegram.token'),
            ]);
            config()->set('telegram.default', 'laraclaw');
        });
    }

    /**
     * Copy email SMTP and IMAP credentials from the LaraClaw config into Laravel's mail and IMAP configs.
     */
    private function configureEmailChannel(): void
    {
        if (! config('laraclaw.channels.email.enabled')) {
            return;
        }

        $smtp = config('laraclaw.channels.email.smtp');
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

        $imap = config('laraclaw.channels.email.imap');
        $mailbox = config('laraclaw.channels.email.imap.mailbox', 'default');
        if ($imap['host']) {
            config([
                "imap.mailboxes.{$mailbox}.host" => $imap['host'],
                "imap.mailboxes.{$mailbox}.port" => $imap['port'],
                "imap.mailboxes.{$mailbox}.encryption" => $imap['encryption'],
                "imap.mailboxes.{$mailbox}.username" => $imap['username'],
                "imap.mailboxes.{$mailbox}.password" => $imap['password'],
            ]);
        }
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
     * Register event listeners for each enabled channel and for agent request logging.
     */
    private function registerEventListeners(): void
    {
        if (config('laraclaw.channels.email.enabled')) {
            Event::listen(MessageReceived::class, EmailListener::class);
        }

        if (config('laraclaw.channels.telegram.enabled')) {
            Event::listen(TelegramMessageReceived::class, TelegramListener::class);
        }

        Event::listen(AgentPrompted::class, LogAgentRequest::class);
    }

    /**
     * Register all Artisan commands provided by this package.
     */
    private function registerCommands(): void
    {
        $this->commands([
            GoogleCalendarAuth::class,
            ChannelAddCommand::class,
            SendReminders::class,
            ProcessHeartbeats::class,
            SetupWizard::class,
            SetupAdmin::class,
            SetupAgent::class,
            SetupCalendar::class,
            SetupChannel::class,
            SetupFiles::class,
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
