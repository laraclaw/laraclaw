<?php

namespace Laraclaw\Tools;

use Carbon\Carbon;
use DateTimeImmutable;
use Exception;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laraclaw\DTOs\CalendarEvent;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Services\Calendar\Contracts\CalendarDriver;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;
use Throwable;

/**
 * Agent tool for listing, creating, updating, and deleting calendar events.
 */
class CalendarManager extends BaseTool
{
    protected array $requiresConfirmation = [
        'delete' => 'Delete event "{title}"?',
    ];

    public function __construct(
        protected IncomingMessage $message,
        private readonly CalendarDriver $driver,
    ) {}

    public function description(): Stringable|string
    {
        return 'Manage calendar events. Operations: ' . implode(', ', $this->operations()) . '. Dates can be natural language ("tomorrow 3pm") or ISO 8601.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->required()->description('The operation to perform: ' . implode(', ', $this->operations())),
            'id' => $schema->string()->description('Event ID (required for update/delete)'),
            'title' => $schema->string()->description('Event title (required for create and delete)'),
            'start' => $schema->string()->description('Start date/time (required for list and create)'),
            'end' => $schema->string()->description('End date/time (required for list, optional for create — defaults to start + 1h)'),
            'description' => $schema->string()->description('Event description'),
            'location' => $schema->string()->description('Event location'),
            'attendees' => $schema->array()->items($schema->string())->description('Email addresses of guests to invite'),
        ];
    }

    /**
     * Run the requested operation and catch any calendar driver exceptions as a string error.
     */
    #[Override]
    public function handle(Request $request): Stringable|string
    {
        try {
            return parent::handle($request);
        } catch (Exception $e) {
            return "Calendar operation failed: {$e->getMessage()}";
        }
    }

    /**
     * Return the list of supported operation names.
     */
    protected function operations(): array
    {
        return ['list', 'create', 'update', 'delete'];
    }

    /**
     * List events within the given date range via the calendar driver.
     */
    protected function list(Request $request): string
    {
        $start = $request['start'] ?? null;
        $end = $request['end'] ?? null;

        if ($start === null || $end === null) {
            return 'Both "start" and "end" are required for the list operation.';
        }

        $startDate = $this->parseDate($start);
        $endDate = $this->parseDate($end);

        if (! $startDate instanceof DateTimeImmutable) {
            return "Could not parse start date: {$start}";
        }
        if (! $endDate instanceof DateTimeImmutable) {
            return "Could not parse end date: {$end}";
        }

        $events = $this->driver->list($startDate, $endDate);

        return collect($events)
            ->map(fn (CalendarEvent $e): array => [
                'id' => $e->id,
                'title' => $e->title,
                'start' => $e->start->format('c'),
                'end' => $e->end->format('c'),
                'description' => $e->description,
                'location' => $e->location,
                'attendees' => $e->attendees,
            ])
            ->toJson(JSON_PRETTY_PRINT);
    }

    /**
     * Create a new calendar event and return its assigned ID.
     */
    protected function create(Request $request): string
    {
        $title = $request['title'] ?? null;
        $start = $request['start'] ?? null;

        if ($title === null) {
            return 'The "title" parameter is required for the create operation.';
        }
        if ($start === null) {
            return 'The "start" parameter is required for the create operation.';
        }

        $startDate = $this->parseDate($start);
        if (! $startDate instanceof DateTimeImmutable) {
            return "Could not parse start date: {$start}";
        }

        $endDate = isset($request['end']) ? $this->parseDate($request['end']) : null;
        if (isset($request['end']) && ! $endDate instanceof DateTimeImmutable) {
            return "Could not parse end date: {$request['end']}";
        }

        $endDate ??= DateTimeImmutable::createFromMutable(Carbon::instance($startDate)->addHour()->toDateTime());

        $event = new CalendarEvent(
            title: $title,
            start: $startDate,
            end: $endDate,
            description: $request['description'] ?? null,
            location: $request['location'] ?? null,
            attendees: $request['attendees'] ?? [],
        );

        $id = $this->driver->create($event);

        return "Event created with ID: {$id}";
    }

    /**
     * Apply partial updates to an existing calendar event by ID.
     */
    protected function update(Request $request): string
    {
        $id = $request['id'] ?? null;
        if ($id === null) {
            return 'The "id" parameter is required for the update operation.';
        }

        $title = $request['title'] ?? null;
        $start = $request['start'] ?? null;
        $end = $request['end'] ?? null;
        $description = $request['description'] ?? null;
        $location = $request['location'] ?? null;
        $attendees = $request['attendees'] ?? null;

        if ($title === null && $start === null && $end === null && $description === null && $location === null && $attendees === null) {
            return 'At least one field (title, start, end, description, location, attendees) is required for the update operation.';
        }

        $startDate = $start !== null ? $this->parseDate($start) : null;
        if ($start !== null && ! $startDate instanceof DateTimeImmutable) {
            return "Could not parse start date: {$start}";
        }

        $endDate = $end !== null ? $this->parseDate($end) : null;
        if ($end !== null && ! $endDate instanceof DateTimeImmutable) {
            return "Could not parse end date: {$end}";
        }

        $event = new CalendarEvent(
            title: $title,
            start: $startDate,
            end: $endDate,
            description: $description,
            location: $location,
            attendees: $attendees,
        );

        $this->driver->update($id, $event);

        return "Event {$id} updated.";
    }

    /**
     * Delete a calendar event by ID (requires user confirmation).
     */
    protected function delete(Request $request): string
    {
        $id = $request['id'] ?? null;
        if ($id === null) {
            return 'The "id" parameter is required for the delete operation.';
        }

        $title = $request['title'] ?? $id;

        $this->driver->delete($id);

        return "Event '{$title}' deleted.";
    }

    /**
     * Parse a plain English or ISO 8601 date string into a DateTimeImmutable.
     * Returns null if the value cannot be understood as a date.
     */
    private function parseDate(string $value): ?DateTimeImmutable
    {
        try {
            return Carbon::parse($value)->toDateTimeImmutable();
        } catch (Throwable) {
            return null;
        }
    }
}
