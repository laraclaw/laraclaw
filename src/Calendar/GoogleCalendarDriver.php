<?php

namespace LaraClaw\Calendar;

use LaraClaw\Calendar\Contracts\CalendarDriver;
use LaraClaw\Calendar\DTOs\CalendarEvent;
use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Spatie\GoogleCalendar\Event as SpatieEvent;

class GoogleCalendarDriver implements CalendarDriver
{
    public function list(DateTimeInterface $start, DateTimeInterface $end): array
    {
        return collect($this->getEvents(Carbon::instance($start), Carbon::instance($end)))
            ->map(fn (SpatieEvent $event) => new CalendarEvent(
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

    public function create(CalendarEvent $event): string
    {
        $spatieEvent = $this->newEvent();
        $spatieEvent->name = $event->title;
        $spatieEvent->startDateTime = Carbon::instance($event->start);
        $spatieEvent->endDateTime = Carbon::instance($event->end);

        if ($event->description !== null) {
            $spatieEvent->description = $event->description;
        }

        if ($event->location !== null) {
            $spatieEvent->location = $event->location;
        }

        foreach ($event->attendees ?? [] as $email) {
            $spatieEvent->addAttendee(['email' => $email]);
        }

        $saved = $spatieEvent->save();

        return $saved->id;
    }

    public function update(string $id, CalendarEvent $event): void
    {
        $spatieEvent = $this->findEvent($id);

        if ($event->title !== null) {
            $spatieEvent->name = $event->title;
        }

        if ($event->start !== null) {
            $spatieEvent->startDateTime = Carbon::instance($event->start);
        }

        if ($event->end !== null) {
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

        $spatieEvent->save();
    }

    public function delete(string $id): void
    {
        $this->findEvent($id)->delete();
    }

    protected function getEvents(Carbon $start, Carbon $end): Collection
    {
        return SpatieEvent::get($start, $end);
    }

    protected function newEvent(): SpatieEvent
    {
        return new SpatieEvent;
    }

    protected function findEvent(string $id): SpatieEvent
    {
        return SpatieEvent::find($id);
    }
}
