<?php

namespace LaraClaw;

use LaraClaw\Calendar\AppleCalendarDriver;
use LaraClaw\Calendar\Contracts\CalendarDriver;
use LaraClaw\Calendar\GoogleCalendarDriver;
use LaraClaw\Commands\CommandRegistry;
use LaraClaw\Commands\NewConversation;
use LaraClaw\Handlers\Email;
use LaraClaw\Handlers\Telegram;
use LaraClaw\Http\Middleware\VerifySlackSignature;
use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Spatie\GoogleCalendar\GoogleCalendar;

class LaraclawServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laraclaw.php', 'laraclaw');

        $this->app->scoped(PendingAudioReply::class);

        $this->app->singleton(CommandRegistry::class, function () {
            $registry = new CommandRegistry;
            $registry->register(new NewConversation);

            return $registry;
        });

        $this->app->singleton(SkillRegistry::class, function () {
            return new SkillRegistry(config('laraclaw.skills.path', base_path('laraclaw/skills')));
        });

        if (class_exists(\SergiX44\Nutgram\Nutgram::class)) {
            $this->app->booting(function () {
                config()->set('nutgram.token', config('laraclaw.telegram.token'));
            });

            $this->app->resolving(\SergiX44\Nutgram\Nutgram::class, function (\SergiX44\Nutgram\Nutgram $bot) {
                $bot->onMessage(Telegram::class);
            });
        }

        if (config('laraclaw.calendar.driver')) {
            $this->app->singleton(CalendarDriver::class, function () {
                return match (config('laraclaw.calendar.driver')) {
                    'google' => new GoogleCalendarDriver,
                    'apple' => new AppleCalendarDriver(
                        server: config('laraclaw.calendar.apple.server'),
                        username: config('laraclaw.calendar.apple.username'),
                        password: config('laraclaw.calendar.apple.password'),
                        calendar: config('laraclaw.calendar.apple.calendar'),
                    ),
                    default => throw new RuntimeException('Unknown calendar driver: '.config('laraclaw.calendar.driver')),
                };
            });
        }
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/laraclaw.php');

        $this->app['router']->aliasMiddleware('slack.signature', VerifySlackSignature::class);

        $this->publishes([
            __DIR__.'/../config/laraclaw.php' => config_path('laraclaw.php'),
        ], 'laraclaw-config');


        if (config('laraclaw.email.enabled')) {
            Event::listen(MessageReceived::class, Email::class);
        }

        if (config('laraclaw.calendar.driver') === 'google') {
            $this->app->extend(GoogleCalendar::class, function (GoogleCalendar $calendar) {
                $client = $calendar->getService()->getClient();
                $tokenPath = config('laraclaw.calendar.google.token_json');

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
}
