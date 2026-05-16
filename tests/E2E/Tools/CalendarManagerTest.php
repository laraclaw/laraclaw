<?php

beforeEach(function (): void {
    $this->requireDestructive();
    $this->requireEnv('ANTHROPIC_API_KEY', 'LARACLAW_GOOGLE_CALENDAR_ID', 'LARACLAW_GOOGLE_CREDENTIALS_JSON');
    $this->authenticatedUser();
});

it('creates a real Google Calendar event', function (): void {
    $reply = $this->postMessage(
        "Use CalendarManager to create an event called 'Laraclaw E2E test event' today at 3pm America/Montevideo time, lasting 15 minutes."
    );

    expect($reply['success'])->toBeTrue();
    $text = strtolower($reply['text']);
    expect(str_contains($text, 'event') || str_contains($text, 'created') || str_contains($text, 'scheduled'))->toBeTrue();
});
