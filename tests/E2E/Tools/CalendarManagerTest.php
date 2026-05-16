<?php

use Spatie\GoogleCalendar\Event;

beforeEach(function (): void {
    $this->requireDestructive();
    $this->requireEnv('ANTHROPIC_API_KEY', 'LARACLAW_CALENDAR_DRIVER', 'LARACLAW_GOOGLE_CALENDAR_ID', 'LARACLAW_GOOGLE_CREDENTIALS_JSON');
    $this->authenticatedUser();
});

it('creates a real Google Calendar event', function (): void {
    // Tomorrow at 15:00 UTC keeps the prompt reproducible no matter where
    // the suite runs and avoids midnight-rollover edge cases.
    $when = now('UTC')->addDay()->setTime(15, 0)->format('Y-m-d\TH:i:s\Z');

    $reply = $this->postMessage(
        "Use CalendarManager to create an event called 'Laraclaw E2E test event' at {$when} (UTC), lasting 15 minutes. Reply only with the event id."
    );

    expect($reply['success'])->toBeTrue();

    // Google Calendar event ids are lowercase base32-ish (alphabet [a-v0-9]) and
    // typically 20+ chars. Requiring word boundaries plus length keeps the assertion
    // from matching prose like "event" or "calendar".
    expect($reply['text'])->toMatch('/\b[a-v0-9]{20,}\b/');

    // Cross-check against Google Calendar so a model that hallucinates an id-shaped
    // string in a failure reply still fails the test.
    $eventIds = collect(Event::get())
        ->pluck('id')
        ->all();
    expect($eventIds)->toContain(trim($reply['text']));
});
