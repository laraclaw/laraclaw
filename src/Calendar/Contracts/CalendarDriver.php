<?php

namespace LaraClaw\Calendar\Contracts;

use DateTimeInterface;
use LaraClaw\DTOs\CalendarEvent;

interface CalendarDriver
{
    /**
     * List all events within the given date range.
     *
     * @param  \DateTimeInterface  $start
     * @param  \DateTimeInterface  $end
     * @return \LaraClaw\DTOs\CalendarEvent[]
     */
    public function list(DateTimeInterface $start, DateTimeInterface $end): array;

    /**
     * Create a new calendar event and return its ID.
     *
     * @param  \LaraClaw\DTOs\CalendarEvent  $event
     * @return string
     */
    public function create(CalendarEvent $event): string;

    /**
     * Update an existing calendar event by ID.
     *
     * @param  string  $id
     * @param  \LaraClaw\DTOs\CalendarEvent  $event
     * @return void
     */
    public function update(string $id, CalendarEvent $event): void;

    /**
     * Delete a calendar event by ID.
     *
     * @param  string  $id
     * @return void
     */
    public function delete(string $id): void;
}
