<?php

namespace LaraClaw\Listeners;

use Illuminate\Support\Facades\File;
use Laravel\Ai\Events\AgentPrompted;

class LogAgentRequest
{
    public function handle(AgentPrompted $event): void
    {
        if (! config('laraclaw.logging.agent_requests')) {
            return;
        }

        $provider = $event->response->meta->provider ?? class_basename($event->prompt->provider);
        $folder = storage_path('logs/laraclaw/api/'.$provider.'/'.now()->format('Y-m-d').'/'.now()->format('His').'_'.$event->invocationId);

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

        File::put($folder.'/request.json', json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        File::put($folder.'/response.json', json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
