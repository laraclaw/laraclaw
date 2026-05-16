<?php

beforeEach(function (): void {
    $this->requireDestructive();
    $this->requireEnv('ANTHROPIC_API_KEY', 'LARACLAW_GOOGLE_CALENDAR_ID', 'LARACLAW_GOOGLE_CREDENTIALS_JSON');
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
    // Google Calendar event ids are lowercase base32-ish, 5+ chars.
    expect($reply['text'])->toMatch('/[a-z0-9]{5,}/');
});
