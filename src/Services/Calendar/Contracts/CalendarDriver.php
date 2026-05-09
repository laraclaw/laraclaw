<?php

namespace Laraclaw\Services\Calendar\Contracts;

use DateTimeInterface;
use Laraclaw\DTOs\CalendarEvent;

interface CalendarDriver
{
    /**
     * List all events within the given date range.
     *
     * @return CalendarEvent[]
     */
    public function list(DateTimeInterface $start, DateTimeInterface $end): array;

    /**
     * Create a new calendar event and return its ID.
     */
    public function create(CalendarEvent $event): string;

    /**
     * Update an existing calendar event by ID.
     */
    public function update(string $id, CalendarEvent $event): void;

    /**
     * Delete a calendar event by ID.
     */
    public function delete(string $id): void;
}
