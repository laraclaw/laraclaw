<?php

namespace Laraclaw\Tools;

use Cron\CronExpression;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Routine;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Agent tool for creating, listing, and cancelling recurring routines scheduled by cron.
 */
class RoutineManager extends BaseTool
{
    /**
     * Return the tool description shown to the agent.
     */
    public function description(): Stringable|string
    {
        return 'Manage recurring scheduled prompts (routines). Operations: create, list, cancel. '
            . 'Use create to schedule a recurring prompt on a cron schedule. '
            . 'Each time the routine fires, the prompt is sent to the agent for processing and the response is delivered. '
            . 'The cron field accepts standard 5-field cron expressions (e.g. "0 9 * * 1" for every Monday at 9am). '
            . 'Translate plain English schedules ("every weekday at 9am") into cron format. '
            . 'Cron expressions are evaluated in the timezone given to you as the current date and time, so no offset maths is needed. '
            . 'Use list to see active routines. '
            . 'Use cancel to deactivate a routine by ID.';
    }

    /**
     * Define the input schema for this tool.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->required()->description('Operation: create, list, or cancel'),
            'id' => $schema->string()->description('Routine ID (required for cancel)'),
            'prompt' => $schema->string()->description('Prompt for the agent to process on each occurrence (required for create)'),
            'cron' => $schema->string()->description('5-field cron expression, e.g. "0 9 * * 1" (required for create)'),
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
     * Validate the cron expression and prompt, then persist a new active Routine record.
     */
    protected function create(Request $request): string
    {
        $prompt = $request['prompt'] ?? null;
        $cron = $request['cron'] ?? null;

        if (! $prompt) {
            return 'The "prompt" parameter is required for create.';
        }
        if (! $cron) {
            return 'The "cron" parameter is required for create.';
        }

        if (! CronExpression::isValidExpression($cron)) {
            return "Invalid cron expression \"{$cron}\". Use standard 5-field syntax, e.g. \"0 9 * * 1\".";
        }

        [$connector, $key] = $this->resolveConnector($request['connector'] ?? null);

        if ($connector === ConnectorType::Api) {
            return 'API threads cannot receive routines because the HTTP request closes before each tick fires. Pick telegram, slack, or email.';
        }

        Routine::create([
            'user_id' => config('laraclaw.auth.admin_user_id'),
            'connector' => $connector,
            'key' => $key,
            'prompt' => $prompt,
            'cron' => $cron,
            'is_active' => true,
        ]);

        return "Routine created with cron \"{$cron}\": {$prompt}";
    }

    /**
     * Return all active routines for the configured admin user as JSON.
     */
    protected function list(Request $request): string
    {
        $routines = Routine::where('user_id', config('laraclaw.auth.admin_user_id'))
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'connector', 'key', 'prompt', 'cron', 'last_run_at']);

        if ($routines->isEmpty()) {
            return 'No active routines.';
        }

        return json_encode($routines->toArray(), JSON_PRETTY_PRINT);
    }

    /**
     * Deactivate a routine by ID (sets is_active to false).
     */
    protected function cancel(Request $request): string
    {
        $id = $request['id'] ?? null;
        if (! $id) {
            return 'The "id" parameter is required for cancel.';
        }

        $routine = Routine::where('id', $id)
            ->where('user_id', config('laraclaw.auth.admin_user_id'))
            ->first();

        if (! $routine) {
            return "Routine {$id} not found.";
        }

        $routine->update(['is_active' => false]);

        return "Routine {$id} cancelled.";
    }
}
