<?php

namespace LaraClaw\Calendar\DTOs;

use DateTimeImmutable;

readonly class CalendarEvent
{
    /**
     * @param  string[]|null  $attendees  Email addresses of guests/invitees (null = unchanged on update)
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
