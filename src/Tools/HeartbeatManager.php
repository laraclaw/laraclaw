<?php

namespace LaraClaw\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use LaraClaw\Models\Heartbeat;
use Laravel\Ai\Tools\Request;
use Stringable;

class HeartbeatManager extends BaseTool
{
    protected function operations(): array
    {
        return ['create', 'list', 'cancel'];
    }

    public function description(): Stringable|string
    {
        return 'Manage recurring scheduled messages (heartbeats). Operations: create, list, cancel. '
            . 'Use create to schedule a recurring message on a cron schedule. '
            . 'The cron field accepts standard 5-field cron expressions (e.g. "0 9 * * 1" for every Monday at 9am). '
            . 'Translate human-friendly schedules ("every weekday at 9am") into cron format using the current timezone. '
            . 'Use list to see active heartbeats. '
            . 'Use cancel to deactivate a heartbeat by ID.';
    }

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

        $channelIdentifier = $this->resolveChannelIdentifier($request['channel'] ?? null);

        Heartbeat::create([
            'user_id' => config('laraclaw.owner'),
            'channel_identifier' => $channelIdentifier,
            'message' => $message,
            'cron' => $cron,
            'is_active' => true,
        ]);

        return "Heartbeat created with cron \"{$cron}\": {$message}";
    }

    protected function list(Request $request): string
    {
        $heartbeats = Heartbeat::where('user_id', config('laraclaw.owner'))
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'channel_identifier', 'message', 'cron', 'last_run_at']);

        if ($heartbeats->isEmpty()) {
            return 'No active heartbeats.';
        }

        return json_encode($heartbeats->toArray(), JSON_PRETTY_PRINT);
    }

    protected function cancel(Request $request): string
    {
        $id = $request['id'] ?? null;
        if (! $id) {
            return 'The "id" parameter is required for cancel.';
        }

        $heartbeat = Heartbeat::where('id', $id)
            ->where('user_id', config('laraclaw.owner'))
            ->first();

        if (! $heartbeat) {
            return "Heartbeat {$id} not found.";
        }

        $heartbeat->update(['is_active' => false]);

        return "Heartbeat {$id} cancelled.";
    }

}
