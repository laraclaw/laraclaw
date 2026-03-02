<?php

namespace LaraClaw\Tools;

use Cron\CronExpression;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use LaraClaw\Models\Heartbeat;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Agent tool for creating, listing, and cancelling recurring heartbeats scheduled by cron.
 */
class HeartbeatManager extends BaseTool
{
    /**
     * Return the tool description shown to the agent.
     */
    public function description(): Stringable|string
    {
        return 'Manage recurring scheduled messages (heartbeats). Operations: create, list, cancel. '
            . 'Use create to schedule a recurring message on a cron schedule. '
            . 'The cron field accepts standard 5-field cron expressions (e.g. "0 9 * * 1" for every Monday at 9am). '
            . 'Translate human-friendly schedules ("every weekday at 9am") into cron format using the current timezone. '
            . 'Use list to see active heartbeats. '
            . 'Use cancel to deactivate a heartbeat by ID.';
    }

    /**
     * Define the input schema for this tool.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->required()->description('Operation: create, list, or cancel'),
            'id' => $schema->string()->description('Heartbeat ID (required for cancel)'),
            'message' => $schema->string()->description('Message to send on each occurrence (required for create)'),
            'cron' => $schema->string()->description('5-field cron expression, e.g. "0 9 * * 1" (required for create)'),
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
     * Validate the cron expression and message, then persist a new active Heartbeat record.
     */
    protected function create(Request $request): string
    {
        $message = $request['message'] ?? null;
        $cron = $request['cron'] ?? null;

        if (! $message) {
            return 'The "message" parameter is required for create.';
        }
        if (! $cron) {
            return 'The "cron" parameter is required for create.';
        }

        if (! CronExpression::isValidExpression($cron)) {
            return "Invalid cron expression \"{$cron}\". Use standard 5-field syntax, e.g. \"0 9 * * 1\".";
        }

        [$channel, $key] = $this->resolveChannel($request['channel'] ?? null);

        Heartbeat::create([
            'user_id' => config('laraclaw.auth.admin_user_id'),
            'channel' => $channel,
            'key' => $key,
            'message' => $message,
            'cron' => $cron,
            'is_active' => true,
        ]);

        return "Heartbeat created with cron \"{$cron}\": {$message}";
    }

    /**
     * Return all active heartbeats for the configured admin user as JSON.
     */
    protected function list(Request $request): string
    {
        $heartbeats = Heartbeat::where('user_id', config('laraclaw.auth.admin_user_id'))
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'channel', 'key', 'message', 'cron', 'last_run_at']);

        if ($heartbeats->isEmpty()) {
            return 'No active heartbeats.';
        }

        return json_encode($heartbeats->toArray(), JSON_PRETTY_PRINT);
    }

    /**
     * Deactivate a heartbeat by ID (sets is_active to false).
     */
    protected function cancel(Request $request): string
    {
        $id = $request['id'] ?? null;
        if (! $id) {
            return 'The "id" parameter is required for cancel.';
        }

        $heartbeat = Heartbeat::where('id', $id)
            ->where('user_id', config('laraclaw.auth.admin_user_id'))
            ->first();

        if (! $heartbeat) {
            return "Heartbeat {$id} not found.";
        }

        $heartbeat->update(['is_active' => false]);

        return "Heartbeat {$id} cancelled.";
    }
}
