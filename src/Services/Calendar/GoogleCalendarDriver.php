<?php

namespace Laraclaw\Services\Calendar;

use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Laraclaw\DTOs\CalendarEvent;
use Laraclaw\Services\Calendar\Contracts\CalendarDriver;
use Spatie\GoogleCalendar\Event as SpatieEvent;

class GoogleCalendarDriver implements CalendarDriver
{
    /**
     * List all events within the given date range via the Google Calendar API.
     *
     * @return CalendarEvent[]
     */
    public function list(DateTimeInterface $start, DateTimeInterface $end): array
    {
        return collect($this->getEvents(Carbon::instance($start), Carbon::instance($end)))
            ->map(fn (SpatieEvent $event): CalendarEvent => new CalendarEvent(
                title: $event->name ?? '',
                start: new DateTimeImmutable($event->startDateTime->toIso8601String()),
                end: new DateTimeImmutable($event->endDateTime->toIso8601String()),
                description: $event->description ?? null,
                location: $event->location ?? null,
                id: $event->id,
                attendees: collect($event->googleEvent->getAttendees())
                    ->map(fn ($a) => $a->getEmail())
                    ->filter()
                    ->values()
                    ->all(),
            ))
            ->all();
    }

    /**
     * Create a new Google Calendar event and return its ID.
     */
    public function create(CalendarEvent $event): string
    {
        return $this->apply($this->newEvent(), $event)->save()->id;
    }

    /**
     * Fetch the existing Google Calendar event and patch the changed fields.
     */
    public function update(string $id, CalendarEvent $event): void
    {
        $this->apply($this->findEvent($id), $event)->save();
    }

    /**
     * Delete a Google Calendar event by ID.
     */
    public function delete(string $id): void
    {
        $this->findEvent($id)->delete();
    }

    /**
     * Retrieve Spatie Google Calendar events for the given range.
     */
    protected function getEvents(Carbon $start, Carbon $end): Collection
    {
        return SpatieEvent::get($start, $end);
    }

    /**
     * Instantiate a new Spatie Event for creation.
     */
    protected function newEvent(): SpatieEvent
    {
        return new SpatieEvent;
    }

    /**
     * Find an existing Spatie Event by its Google Calendar ID.
     */
    protected function findEvent(string $id): SpatieEvent
    {
        return SpatieEvent::find($id);
    }

    /**
     * Copy every field the event sets onto the Spatie event, leaving the rest as they were.
     *
     * A null attendee list means leave the guests alone; an empty one clears them.
     */
    private function apply(SpatieEvent $spatieEvent, CalendarEvent $event): SpatieEvent
    {
        if ($event->title !== null) {
            $spatieEvent->name = $event->title;
        }

        if ($event->start instanceof DateTimeImmutable) {
            $spatieEvent->startDateTime = Carbon::instance($event->start);
        }

        if ($event->end instanceof DateTimeImmutable) {
            $spatieEvent->endDateTime = Carbon::instance($event->end);
        }

        if ($event->description !== null) {
            $spatieEvent->description = $event->description;
        }

        if ($event->location !== null) {
            $spatieEvent->location = $event->location;
        }

        if ($event->attendees !== null) {
            $spatieEvent->googleEvent->setAttendees([]);

            foreach ($event->attendees as $email) {
                $spatieEvent->addAttendee(['email' => $email]);
            }
        }

        return $spatieEvent;
    }
}
