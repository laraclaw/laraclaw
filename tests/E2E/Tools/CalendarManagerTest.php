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
    $when = now('UTC')->addDay()->setTime(15, 0);

    $reply = $this->postMessage(
        "Use CalendarManager to create an event called 'Laraclaw E2E test event' at {$when->format('Y-m-d\TH:i:s\Z')} (UTC), lasting 15 minutes. Reply only with the event id."
    );

    expect($reply['success'])->toBeTrue();

    // Google Calendar event ids are lowercase base32-ish (alphabet [a-v0-9]) and
    // typically 20+ chars. Extract the id from the reply via regex so chatty
    // model output ("The event id is abc123...") still works.
    preg_match('/\b[a-v0-9]{20,}\b/', $reply['text'], $match);
    expect($match)->not->toBeEmpty();
    $eventId = $match[0];

    // Cross-check against Google Calendar, scoped to a one-hour window around the
    // event, so a calendar that has accumulated many events from earlier runs does
    // not cause the lookup to miss this one in the default 250-event page.
    $events = Event::get(
        $when->copy()->subMinutes(30),
        $when->copy()->addMinutes(45),
    );

    // Spatie's Event exposes its properties through __get but does not implement
    // __isset, so the data_get() helpers behind pluck() and firstWhere() read them
    // as null. Reach for the property directly instead.
    $ids = $events->map(fn (Event $event): string => $event->id)->all();

    expect($ids)->toContain($eventId);

    // Clean up so re-running the suite does not litter the calendar.
    $events->first(fn (Event $event): bool => $event->id === $eventId)?->delete();
});
