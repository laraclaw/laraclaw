<?php

namespace App\Listeners;

use Illuminate\Support\Facades\File;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Events\AgentPrompted;

class LogAgentRequest
{
    public function handle(AgentPrompted $event): void
    {
        $agent = $event->prompt->agent;

        $conversationId = in_array(RemembersConversations::class, class_uses_recursive($agent))
            ? ($agent->currentConversation() ?? 'no-conversation')
            : 'no-conversation';

        $requestId = now()->format('Y-m-d_H_i_s_u');
        $directory = storage_path("logs/laraclaw/requests/{$conversationId}/{$requestId}");

        File::ensureDirectoryExists($directory, 0755, true);

        $request = [
            'invocation_id' => $event->invocationId,
            'prompt' => $event->prompt->prompt,
            'provider' => $event->response->meta->provider,
            'model' => $event->response->meta->model,
            'instructions' => (string) $agent->instructions(),
        ];

        $response = [
            'invocation_id' => $event->invocationId,
            'text' => $event->response->text,
            'usage' => $event->response->usage->toArray(),
            'meta' => $event->response->meta->toArray(),
            'tool_calls' => $event->response->toolCalls->toArray(),
            'tool_results' => $event->response->toolResults->toArray(),
        ];

        $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        File::put($directory . '/request.json', json_encode($request, $jsonFlags));
        File::put($directory . '/response.json', json_encode($response, $jsonFlags));
    }
}
