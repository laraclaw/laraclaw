<?php

namespace LaraClaw\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use LaraClaw\Agents\ChatBotAgent;
use LaraClaw\Connectors\ApiConnector;
use LaraClaw\Commands\CommandRegistry;
use LaraClaw\DTOs\Attachment;
use LaraClaw\Models\Thread;
use LaraClaw\Services\Attachments;
use Laravel\Ai\Responses\AgentResponse;

/**
 * Handle incoming API requests authenticated via a hashed Bearer token.
 */
class ApiController extends Controller
{
    public function __construct(
        private readonly Attachments $attachments,
        private readonly CommandRegistry $commands,
    ) {}

    /**
     * Validate the request, resolve the thread, prompt the agent and return its response.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'text' => ['required_without:attachments', 'nullable', 'string'],
            'key' => ['nullable', 'string'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file'],
        ]);

        $key = $request->input('key', (string) Str::uuid());

        $incomingMessage = ApiConnector::createIncomingMessageFrom(
            text: $request->input('text'),
            key: $key,
            files: $request->file('attachments', []),
            attachments: $this->attachments,
        );

        $thread = Thread::forMessage($incomingMessage);

        // Handle commands
        if ($command = $this->commands->match($incomingMessage->text ?? '')) {
            $command->handle($incomingMessage, $thread);

            return response()->json(['success' => true]);
        }

        // Prompt the agent synchronously and return the response inline
        $agent = resolve(ChatBotAgent::class, ['message' => $incomingMessage, 'thread' => $thread]);
        $response = $agent->prompt(...$incomingMessage->toAgentInput());

        $thread->update(['conversation_id' => $response->conversationId]);

        return $this->buildResponse($response, $thread, $incomingMessage->uuid);
    }

    /**
     * Build the JSON response with the agent reply and any outbound attachments.
     */
    private function buildResponse(AgentResponse $response, Thread $thread, string $uuid): JsonResponse
    {
        return response()->json([
            'success' => true,
            'text' => $response->text,
            'key' => $thread->key,
            'attachments' => $this->attachments->outbound($uuid)->getAll()
                ->map(fn (Attachment $a) => [
                    'filename' => $a->filename,
                    'mime_type' => $a->mimeType,
                    'path' => $a->path,
                ])
                ->values()
                ->all(),
        ]);
    }
}
