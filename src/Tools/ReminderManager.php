<?php

namespace Laraclaw\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Reminder;
use Laravel\Ai\Tools\Request;
use Stringable;

use function Laraclaw\Support\appTimezone;
use function Laraclaw\Support\parseNaturalDate;

/**
 * Agent tool for creating, listing, and cancelling single scheduled reminders.
 */
class ReminderManager extends BaseTool
{
    protected array $requires = [
        'create' => ['message', 'remind_at'],
        'cancel' => ['id'],
    ];

    /**
     * Return the tool description shown to the agent.
     */
    public function description(): Stringable|string
    {
        return 'Manage one-shot scheduled reminders. Operations: create, list, cancel. '
            . 'Use create to schedule a message to be sent at a specific time. '
            . 'Use list to see upcoming reminders. '
            . 'Use cancel to delete a reminder by ID.';
    }

    /**
     * Define the input schema for this tool.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->required()->description('Operation: create, list, or cancel'),
            'id' => $schema->string()->description('Reminder ID (required for cancel)'),
            'message' => $schema->string()->description('Message to send (required for create). It is delivered word for word at that time, so write what the user should read then: "Time to do the dishes", not "Remind me to do the dishes".'),
            'remind_at' => $schema->string()->description('When to send. Prefer ISO 8601 worked out from the current date and time you were given. Plain English like "tomorrow at 10am" also works. (required for create)'),
            'connector' => $schema->string()->description('Connector type to send on: telegram, slack, or email. Defaults to the current connector.'),
        ];
    }

    /**
     * Return the list of supported operation names.
     */
    protected function operations(): array
    {
        return ['create', 'list', 'cancel'];
    }

    /**
     * Parse the remind_at date, resolve the connector, and persist a new Reminder record.
     */
    protected function create(Request $request): string
    {
        $remindAt = parseNaturalDate($request['remind_at']);

        if (! $remindAt) {
            return "Could not parse remind_at: {$request['remind_at']}. Work out the exact time yourself and pass it as ISO 8601.";
        }

        [$connector, $key] = $this->resolveConnector($request['connector'] ?? null);

        if ($connector === ConnectorType::Api) {
            return 'API threads cannot receive scheduled reminders because the HTTP request closes before the reminder fires. Pick telegram, slack, or email.';
        }

        Reminder::create([
            'user_id' => config('laraclaw.auth.admin_user_id'),
            'connector' => $connector,
            'key' => $key,
            'message' => $request['message'],
            'remind_at' => $remindAt,
        ]);

        return "Reminder set for {$remindAt->toDateTimeString()} (" . appTimezone() . "): {$request['message']}";
    }

    /**
     * Return all pending reminders for the configured admin user as JSON.
     */
    protected function list(Request $request): string
    {
        $reminders = $this->owned()
            ->orderBy('remind_at')
            ->get(['id', 'connector', 'key', 'message', 'remind_at']);

        return $reminders->isEmpty()
            ? 'No pending reminders.'
            : $reminders->toJson(JSON_PRETTY_PRINT);
    }

    /**
     * Delete an unsent reminder by ID.
     */
    protected function cancel(Request $request): string
    {
        $reminder = $this->owned()->find($request['id']);

        if (! $reminder) {
            return "Reminder {$request['id']} not found or already sent.";
        }

        $reminder->delete();

        return "Reminder {$request['id']} cancelled.";
    }

    /**
     * Query the unsent reminders that belong to the configured admin user.
     */
    private function owned(): Builder
    {
        return Reminder::where('user_id', config('laraclaw.auth.admin_user_id'))->whereNull('sent_at');
    }
}
