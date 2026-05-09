<?php

namespace Laraclaw\Listeners;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Log token usage and cost, and optionally write full request/response payloads to disk.
 */
class LogAgentRequest
{
    /**
     * Log token usage and, when enabled, write request/response payloads to disk.
     */
    public function handle(AgentPrompted $event): void
    {
        $this->logUsage($event->response->usage);

        if (config('laraclaw.logging.agent_requests')) {
            $this->logRequestToDisk($event);
        }
    }

    /**
     * Log token counts and estimated USD cost.
     */
    private function logUsage(Usage $usage): void
    {
        // Sonnet 4.6 pricing per million tokens (USD)
        $inputRate = 3.00;
        $outputRate = 15.00;
        $cacheWriteRate = 3.75;
        $cacheReadRate = 0.30;

        $cost = ($usage->promptTokens * $inputRate / 1_000_000)
            + ($usage->completionTokens * $outputRate / 1_000_000)
            + ($usage->cacheWriteInputTokens * $cacheWriteRate / 1_000_000)
            + ($usage->cacheReadInputTokens * $cacheReadRate / 1_000_000);

        Log::info('Agent usage', [
            'input_tokens' => $usage->promptTokens,
            'output_tokens' => $usage->completionTokens,
            'cache_write_tokens' => $usage->cacheWriteInputTokens,
            'cache_read_tokens' => $usage->cacheReadInputTokens,
            'total_tokens' => $usage->promptTokens + $usage->completionTokens + $usage->cacheWriteInputTokens + $usage->cacheReadInputTokens,
            'cost_usd' => round($cost, 6),
        ]);
    }

    /**
     * Write the prompt and response payloads to JSON files, one pair per agent invocation.
     */
    private function logRequestToDisk(AgentPrompted $event): void
    {
        $provider = $event->response->meta->provider ?? class_basename($event->prompt->provider);
        $folder = storage_path('logs/laraclaw/api/' . $provider . '/' . now()->format('Y-m-d') . '/' . now()->format('His') . '_' . $event->invocationId);

        File::ensureDirectoryExists($folder);

        $request = [
            'invocation_id' => $event->invocationId,
            'timestamp' => now()->toIso8601String(),
            'provider' => $provider,
            'model' => $event->prompt->model,
            'prompt' => $event->prompt->prompt,
            'attachments' => $event->prompt->attachments->count(),
        ];

        $response = [
            'invocation_id' => $event->invocationId,
            'timestamp' => now()->toIso8601String(),
            'text' => $event->response->text,
            'usage' => $event->response->usage->toArray(),
            'meta' => $event->response->meta->toArray(),
            'steps' => $event->response->steps->map(fn ($s) => $s->toArray())->values()->all(),
            'tool_calls' => $event->response->toolCalls->map(fn ($tc) => $tc->toArray())->values()->all(),
            'tool_results' => $event->response->toolResults->map(fn ($tr) => $tr->toArray())->values()->all(),
        ];

        File::put($folder . '/request.json', json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        File::put($folder . '/response.json', json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
