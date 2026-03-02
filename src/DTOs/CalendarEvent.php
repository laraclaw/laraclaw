<?php

namespace LaraClaw\DTOs;

use DateTimeImmutable;

/**
 * DTO representing a calendar event, used for creating, updating, and listing events.
 */
readonly class CalendarEvent
{
    /**
     * @param  string|null  $title  Event title / summary.
     * @param  DateTimeImmutable|null  $start  Start date and time.
     * @param  DateTimeImmutable|null  $end  End date and time.
     * @param  string|null  $description  Body / notes for the event.
     * @param  string|null  $location  Physical or virtual location.
     * @param  string|null  $id  Event ID assigned by the calendar provider. Null when creating a new event.
     * @param  string[]|null  $attendees  Guest email addresses. Null means leave unchanged on update; an empty array removes all guests.
     */
    public function __construct(
        public ?string $title = null,
        public ?DateTimeImmutable $start = null,
        public ?DateTimeImmutable $end = null,
        public ?string $description = null,
        public ?string $location = null,
        public ?string $id = null,
        public ?array $attendees = null,
    ) {}
}
