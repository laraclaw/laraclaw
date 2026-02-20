<?php

namespace LaraClaw\Calendar\Contracts;

use LaraClaw\Calendar\DTOs\CalendarEvent;
use DateTimeInterface;

interface CalendarDriver
{
    /** @return CalendarEvent[] */
    public function list(DateTimeInterface $start, DateTimeInterface $end): array;

    public function create(CalendarEvent $event): string;

    public function update(string $id, CalendarEvent $event): void;

    public function delete(string $id): void;
}
