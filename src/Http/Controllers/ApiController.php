<?php

namespace Laraclaw\Http\Controllers;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laraclaw\Agents\ChatBotAgent;
use Laraclaw\Approvals\ApprovalFlow;
use Laraclaw\Commands\CommandRegistry;
use Laraclaw\Connectors\Api;
use Laraclaw\DTOs\Attachment;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Models\Thread;
use Laraclaw\Services\Attachments;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Responses\AgentResponse;

/**
 * Handle incoming API requests authenticated via a hashed Bearer token.
 */
class ApiController extends Controller
{
    /**
     * Inject the attachment writer and the command registry consulted before dispatching to the agent.
     */
    public function __construct(
        private readonly Attachments $attachments,
        private readonly CommandRegistry $commands,
        private readonly ApprovalFlow $approvals,
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

        $clientKey = blank($request->input('key')) ? (string) Str::uuid() : $request->input('key');

        // Scope the thread lookup key to the authenticated user so two API users
        // who happen to send the same client-supplied key never share a thread row,
        // conversation_id, or persona. The client never sees this prefix.
        $key = $request->user()->getAuthIdentifier() . ':' . $clientKey;

        $incomingMessage = Api::createIncomingMessageFrom(
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

        // Two requests on one thread would otherwise read the same conversation and
        // start one each, so the second waits here for the first to finish and then
        // continues what it saved. Queued turns take this very same lock by name.
        try {
            return Cache::lock($thread->lockKey(), (int) config('laraclaw.queue.thread_lock.expires_after', 900))
                ->block(
                    (int) config('laraclaw.queue.thread_lock.sync_wait_for', 30),
                    fn (): JsonResponse => $this->runTurn($thread->refresh(), $incomingMessage, $clientKey),
                );
        } catch (LockTimeoutException) {
            return response()->json([
                'success' => false,
                'message' => 'Another message on this conversation is still being answered. Try again shortly.',
            ], 429);
        }
    }

    /**
     * Prompt the agent synchronously and build the reply.
     *
     * Called with the thread lock held and the row read again, so the agent
     * continues whatever conversation the request ahead of it saved.
     */
    private function runTurn(Thread $thread, IncomingMessage $incomingMessage, string $clientKey): JsonResponse
    {
        $agent = resolve(ChatBotAgent::class, ['message' => $incomingMessage, 'thread' => $thread]);

        // When the agent paused on a gated tool call, this request is the client's
        // answer to it and resumes the paused run instead of starting a new turn.
        $decisions = $this->approvals->decisionsFrom($thread, $incomingMessage);

        try {
            $response = $decisions
                ? $agent->prompt($decisions)
                : $agent->prompt(...$incomingMessage->toAgentInput());
        } catch (ApprovalMismatchException $e) {
            // Our record of the pause is stale. Drop it so the next request is read
            // as a fresh message, and let the SDK render its 409 with the real state.
            $this->approvals->forget($thread);

            throw $e;
        }

        $thread->update(['conversation_id' => $response->conversationId]);

        $question = $this->approvals->capture($thread, $response);

        return $this->buildResponse($response, $clientKey, $incomingMessage->uuid, $question);
    }

    /**
     * Build the JSON response with the agent reply and any outbound attachments.
     *
     * A paused run has no reply text of its own, so the approval question stands in
     * for it and the pending calls are listed alongside for clients that render them.
     */
    private function buildResponse(AgentResponse $response, string $key, string $uuid, ?string $question): JsonResponse
    {
        return response()->json([
            'success' => true,
            'text' => $question ?? $response->text,
            'awaiting_approval' => $question !== null,
            'approvals' => $response->pendingApprovals->toArray(),
            'key' => $key,
            'attachments' => $this->attachments->outbound($uuid)->getAll()
                ->map(fn (Attachment $a): array => [
                    'filename' => $a->filename,
                    'mime_type' => $a->mimeType,
                    'path' => $a->path,
                ])
                ->values()
                ->all(),
        ]);
    }
}
