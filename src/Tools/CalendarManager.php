<?php

namespace Laraclaw\Tools;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laraclaw\DTOs\CalendarEvent;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Services\Calendar\Contracts\CalendarDriver;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

use function Laraclaw\Support\appTimezone;
use function Laraclaw\Support\parseNaturalDate;

/**
 * Agent tool for listing, creating, updating, and deleting calendar events.
 */
class CalendarManager extends BaseTool
{
    private const array UPDATABLE = ['title', 'start', 'end', 'description', 'location', 'attendees'];

    protected array $requiresApproval = [
        'delete' => 'Delete event "{title}"?',
    ];

    protected array $requires = [
        'list' => ['start', 'end'],
        'create' => ['title', 'start'],
        'update' => ['id'],
        'delete' => ['id'],
    ];

    /**
     * Bind the inbound message and the resolved calendar driver (Google or Apple).
     */
    public function __construct(
        protected IncomingMessage $message,
        private readonly CalendarDriver $driver,
    ) {}

    /**
     * Return the tool description shown to the agent.
     */
    public function description(): Stringable|string
    {
        return 'Manage calendar events. Operations: ' . implode(', ', $this->operations()) . '. Dates can be natural language ("tomorrow 3pm") or ISO 8601.';
    }

    /**
     * Define the input schema for this tool.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->required()->description('The operation to perform: ' . implode(', ', $this->operations())),
            'id' => $schema->string()->description('Event ID (required for update/delete)'),
            'title' => $schema->string()->description('Event title (required for create and delete)'),
            'start' => $schema->string()->description('Start date/time (required for list and create)'),
            'end' => $schema->string()->description('End date/time (required for list, optional for create, defaults to start + 1h)'),
            'description' => $schema->string()->description('Event description'),
            'location' => $schema->string()->description('Event location'),
            'attendees' => $schema->array()->items($schema->string())->description('Email addresses of guests to invite'),
        ];
    }

    /**
     * Run the requested operation, reporting an unreadable date or a driver failure as text.
     */
    #[Override]
    public function handle(Request $request): Stringable|string
    {
        try {
            return parent::handle($request);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
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
        $events = $this->driver->list($this->date($request, 'start'), $this->date($request, 'end'));

        // Calendar servers answer in whatever timezone the event was written in,
        // which can be any of several across one list. Normalize them so the
        // agent is comparing like with like against the time it was given.
        $timezone = new DateTimeZone(appTimezone());

        return collect($events)
            ->map(fn (CalendarEvent $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'start' => $event->start->setTimezone($timezone)->format('c'),
                'end' => $event->end->setTimezone($timezone)->format('c'),
                'description' => $event->description,
                'location' => $event->location,
                'attendees' => $event->attendees,
            ])
            ->toJson(JSON_PRETTY_PRINT);
    }

    /**
     * Create a new calendar event and return its assigned ID.
     */
    protected function create(Request $request): string
    {
        $start = $this->date($request, 'start');

        $id = $this->driver->create(new CalendarEvent(
            title: $request['title'],
            start: $start,
            end: $this->date($request, 'end') ?? $start->modify('+1 hour'),
            description: $request['description'] ?? null,
            location: $request['location'] ?? null,
            attendees: $request['attendees'] ?? [],
        ));

        return "Event created with ID: {$id}";
    }

    /**
     * Apply partial updates to an existing calendar event by ID.
     */
    protected function update(Request $request): string
    {
        if (! collect(self::UPDATABLE)->contains(fn (string $field): bool => isset($request[$field]))) {
            return 'At least one field (' . implode(', ', self::UPDATABLE) . ') is required for the update operation.';
        }

        $this->driver->update($request['id'], new CalendarEvent(
            title: $request['title'] ?? null,
            start: $this->date($request, 'start'),
            end: $this->date($request, 'end'),
            description: $request['description'] ?? null,
            location: $request['location'] ?? null,
            attendees: $request['attendees'] ?? null,
        ));

        return "Event {$request['id']} updated.";
    }

    /**
     * Delete a calendar event by ID (requires user confirmation).
     */
    protected function delete(Request $request): string
    {
        $this->driver->delete($request['id']);

        $title = $request['title'] ?? $request['id'];

        return "Event '{$title}' deleted.";
    }

    /**
     * Read a date argument, or null when the request leaves it out.
     *
     * @throws InvalidArgumentException when the value is present but cannot be read as a date
     */
    private function date(Request $request, string $key): ?DateTimeImmutable
    {
        $value = $request[$key] ?? null;

        if ($value === null) {
            return null;
        }

        return parseNaturalDate($value)?->toDateTimeImmutable()
            ?? throw new InvalidArgumentException("Could not parse {$key} date: {$value}");
    }
}
