<?php

namespace Laraclaw\Console\Commands;

use Illuminate\Console\Command;
use Laraclaw\Console\Concerns\ConfiguresEnv;

use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

/**
 * Configure the Laraclaw Calendar Manager tool.
 */
class SetupCalendar extends Command
{
    use ConfiguresEnv;

    protected $signature = 'laraclaw:setup-calendar';

    protected $description = 'Configure the Laraclaw Calendar Manager tool';

    public function handle(): int
    {
        $this->heading('📅 Calendar Manager');

        info('Select a calendar driver:');

        $driver = select(
            label: 'Calendar driver',
            options: ['google' => 'Google Calendar', 'apple' => 'Apple CalDAV'],
            default: $this->readEnv('LARACLAW_CALENDAR_DRIVER') ?? 'google',
        );

        match ($driver) {
            'google' => $this->setupGoogleCalendar(),
            'apple' => $this->setupAppleCalendar(),
        };

        return self::SUCCESS;
    }

    private function setupGoogleCalendar(): void
    {
        $calendarId = $this->askEnv('Google Calendar ID (normally, your email address)', 'LARACLAW_GOOGLE_CALENDAR_ID', placeholder: 'you@gmail.com');

        $steps = implode(PHP_EOL, [
            '  1. Go to https://console.cloud.google.com and select or create a project.',
            '  2. Enable the Google Calendar API under APIs & Services.',
            '  3. Go to APIs & Services → Credentials.',
            '  4. Click Create Credentials → OAuth client ID, choose Desktop app.',
            '  5. Download the JSON file to the root of your app and rename it to `oauth-credentials.json`.',
        ]);
        $credentialsPath = base_path('oauth-credentials.json');

        info('To connect Google Calendar, you need an OAuth credentials file:' . PHP_EOL . PHP_EOL . $steps);
        info("Save the file to: {$credentialsPath}");

        spin(function () use ($credentialsPath): void {
            while (! file_exists($credentialsPath)) {
                sleep(1);
            }
        }, 'Waiting for oauth-credentials.json…');

        info('Great! The credentials file was found.');

        $this->saveEnv([
            'LARACLAW_CALENDAR_DRIVER' => 'google',
            'LARACLAW_GOOGLE_CALENDAR_ID' => $calendarId,
            'LARACLAW_GOOGLE_CREDENTIALS_JSON' => $credentialsPath,
            'LARACLAW_GOOGLE_TOKEN_JSON' => base_path('oauth-token.json'),
        ]);

        $this->call('laraclaw:google-calendar-auth');
    }

    private function setupAppleCalendar(): void
    {
        $server = $this->askEnv('CalDAV server URL', 'LARACLAW_APPLE_CALDAV_SERVER', input: fn (): string => text('CalDAV server URL', default: 'https://caldav.icloud.com', required: true));
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
}
