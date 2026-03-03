<?php

namespace LaraClaw\Console\Commands;

use Google_Client;
use Google_Service_Calendar;
use Illuminate\Console\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\text;

/**
 * Artisan command that completes the Google Calendar OAuth flow and saves the token.
 */
class GoogleCalendarAuth extends Command
{
    protected $signature = 'laraclaw:google-calendar-auth';

    protected $description = 'Authenticate with Google Calendar via OAuth';

    /**
     * Guide the user through the OAuth authorization URL flow and persist the token.
     */
    public function handle(): int
    {
        $credentialsJson = config('laraclaw.tools.calendar_manager.google.credentials_json');
        $tokenJson = config('laraclaw.tools.calendar_manager.google.token_json');

        if (! file_exists($credentialsJson)) {
            error("Credentials file not found at: {$credentialsJson}");
            info('Download your OAuth 2.0 credentials from the Google Cloud Console:');
            info('APIs & Services → Credentials → Create Credentials → OAuth client ID (Desktop app)');

            return self::FAILURE;
        }

        $client = new Google_Client;
        $client->setAuthConfig($credentialsJson);
        $client->setScopes([Google_Service_Calendar::CALENDAR]);
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        $authUrl = $client->createAuthUrl();

        info('Visit this URL to authorize the application:');

        echo ' ' . $authUrl . PHP_EOL . PHP_EOL;

        info('You will be redirected to a localhost page. Copy the full URL of that page and paste it below.');

        $url = text('Paste the full redirect URL here', required: true);

        parse_str(parse_url(trim($url), PHP_URL_QUERY), $params);
        $code = $params['code'] ?? trim($url);

        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            error('Failed to fetch token: ' . $token['error_description'] ?? $token['error']);

            return self::FAILURE;
        }

        $tokenDir = dirname($tokenJson);
        if (! is_dir($tokenDir)) {
            mkdir($tokenDir, 0755, true);
        }

        file_put_contents($tokenJson, json_encode($token));

        info('Token saved. Google Calendar is ready to use.');

        return self::SUCCESS;
    }
}
