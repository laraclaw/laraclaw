<?php

namespace LaraClaw\Tools;

use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use LaraClaw\Models\Reminder;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Agent tool for creating, listing, and cancelling single scheduled reminders.
 */
class ReminderManager extends BaseTool
{
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
            'message' => $schema->string()->description('Message to send (required for create)'),
            'remind_at' => $schema->string()->description('When to send — ISO 8601 or natural language, e.g. "tomorrow at 10am" (required for create)'),
            'channel' => $schema->string()->description('Channel type to send on: telegram, slack, or email. Defaults to the current channel.'),
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
     * Parse the remind_at date, resolve the channel, and persist a new Reminder record.
     */
    protected function create(Request $request): string
    {
        $message = $request['message'] ?? null;
        $remindAt = $request['remind_at'] ?? null;

        if (! $message) {
            return 'The "message" parameter is required for create.';
        }
        if (! $remindAt) {
            return 'The "remind_at" parameter is required for create.';
        }

        try {
            $remindAtDate = Carbon::parse($remindAt);
        } catch (Throwable) {
            return "Could not parse remind_at: {$remindAt}";
        }

        [$channel, $key] = $this->resolveChannel($request['channel'] ?? null);

        Reminder::create([
            'user_id' => config('laraclaw.auth.admin_user_id'),
            'channel' => $channel,
            'key' => $key,
            'message' => $message,
            'remind_at' => $remindAtDate,
        ]);

        return "Reminder set for {$remindAtDate->toDateTimeString()}: {$message}";
    }

    /**
     * Return all pending reminders for the configured admin user as JSON.
     */
    protected function list(Request $request): string
    {
        $reminders = Reminder::where('user_id', config('laraclaw.auth.admin_user_id'))
            ->whereNull('sent_at')
            ->orderBy('remind_at')
            ->get(['id', 'channel', 'key', 'message', 'remind_at']);

        if ($reminders->isEmpty()) {
            return 'No pending reminders.';
        }

        return json_encode($reminders->toArray(), JSON_PRETTY_PRINT);
    }

    /**
     * Delete an unsent reminder by ID.
     */
    protected function cancel(Request $request): string
    {
        $id = $request['id'] ?? null;
        if (! $id) {
            return 'The "id" parameter is required for cancel.';
        }

        $reminder = Reminder::where('id', $id)
            ->where('user_id', config('laraclaw.auth.admin_user_id'))
            ->whereNull('sent_at')
            ->first();

        if (! $reminder) {
            return "Reminder {$id} not found or already sent.";
        }

        $reminder->delete();

        return "Reminder {$id} cancelled.";
    }
}
