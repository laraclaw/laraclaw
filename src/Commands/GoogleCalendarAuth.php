<?php

namespace LaraClaw\Commands;

use Google_Client;
use Google_Service_Calendar;
use Illuminate\Console\Command;

class GoogleCalendarAuth extends Command
{
    protected $signature = 'laraclaw:google-calendar-auth';

    protected $description = 'Authenticate with Google Calendar via OAuth';

    public function handle(): int
    {
        $credentialsJson = config('laraclaw.calendar.google.credentials_json');
        $tokenJson = config('laraclaw.calendar.google.token_json');

        if (! file_exists($credentialsJson)) {
            $this->error("Credentials file not found at: {$credentialsJson}");
            $this->line('Download your OAuth 2.0 credentials from the Google Cloud Console:');
            $this->line('  APIs & Services → Credentials → Create Credentials → OAuth client ID (Desktop app)');

            return self::FAILURE;
        }

        $client = new Google_Client;
        $client->setAuthConfig($credentialsJson);
        $client->setScopes([Google_Service_Calendar::CALENDAR]);
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        $authUrl = $client->createAuthUrl();

        $this->line('');
        $this->line('Visit this URL to authorize the application:');
        $this->line('');
        $this->line("  <href={$authUrl}>{$authUrl}</href>");
        $this->line('');

        $code = $this->ask('Paste the authorization code here');

        if (! $code) {
            $this->error('No code provided.');

            return self::FAILURE;
        }

        $token = $client->fetchAccessTokenWithAuthCode(trim($code));

        if (isset($token['error'])) {
            $this->error('Failed to fetch token: '.$token['error_description'] ?? $token['error']);

            return self::FAILURE;
        }

        $tokenDir = dirname($tokenJson);
        if (! is_dir($tokenDir)) {
            mkdir($tokenDir, 0755, true);
        }

        file_put_contents($tokenJson, json_encode($token));

        $this->info("Token saved to: {$tokenJson}");
        $this->line('Google Calendar is ready to use.');

        return self::SUCCESS;
    }
}
