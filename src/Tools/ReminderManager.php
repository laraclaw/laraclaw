<?php

namespace LaraClaw\Tools;

use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use LaraClaw\Models\Reminder;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class ReminderManager extends BaseTool
{
    protected function operations(): array
    {
        return ['create', 'list', 'cancel'];
    }

    public function description(): Stringable|string
    {
        return 'Manage one-shot scheduled reminders. Operations: create, list, cancel. '
            . 'Use create to schedule a message to be sent at a specific time. '
            . 'Use list to see upcoming reminders. '
            . 'Use cancel to delete a reminder by ID.';
    }

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

        $channelIdentifier = $this->resolveChannelIdentifier($request['channel'] ?? null);

        Reminder::create([
            'user_id' => config('laraclaw.owner'),
            'channel_identifier' => $channelIdentifier,
            'message' => $message,
            'remind_at' => $remindAtDate,
        ]);

        return "Reminder set for {$remindAtDate->toDateTimeString()}: {$message}";
    }

    protected function list(Request $request): string
    {
        $reminders = Reminder::where('user_id', config('laraclaw.owner'))
            ->whereNull('sent_at')
            ->orderBy('remind_at')
            ->get(['id', 'channel_identifier', 'message', 'remind_at']);

        if ($reminders->isEmpty()) {
            return 'No pending reminders.';
        }

        return json_encode($reminders->toArray(), JSON_PRETTY_PRINT);
    }

    protected function cancel(Request $request): string
    {
        $id = $request['id'] ?? null;
        if (! $id) {
            return 'The "id" parameter is required for cancel.';
        }

        $reminder = Reminder::where('id', $id)
            ->where('user_id', config('laraclaw.owner'))
            ->whereNull('sent_at')
            ->first();

        if (! $reminder) {
            return "Reminder {$id} not found or already sent.";
        }

        $reminder->delete();

        return "Reminder {$id} cancelled.";
    }

}
