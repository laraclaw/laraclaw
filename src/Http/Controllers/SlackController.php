<?php

namespace Laraclaw\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laraclaw\Agents\ChatBotAgent;
use Laraclaw\Approvals\ApprovalFlow;
use Laraclaw\Commands\CommandRegistry;
use Laraclaw\Connectors\Slack;
use Laraclaw\Models\Thread;
use Laraclaw\Services\Attachments;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

/**
 * Handles incoming Slack event webhook requests.
 */
class SlackController extends Controller
{
    /**
     * Inject the attachment writer, the outbound Slack client, and the command registry.
     */
    public function __construct(
        private readonly Attachments $attachments,
        private readonly Slack $connector,
        private readonly CommandRegistry $commands,
        private readonly ApprovalFlow $approvals,
    ) {}

    /**
     * Process a Slack event.
     *
     * Slack expects a 200 OK response or it will retry the request
     */
    public function __invoke(Request $request): JsonResponse
    {
        // Return the challenge string when the webhook is configured for URL verification
        if ($request->input('type') === 'url_verification') {
            return response()->json(['challenge' => $request->input('challenge')]);
        }

        // Validate the incoming Slack event
        try {
            Slack::validateEvent($request);
        } catch (ValidationException $e) {
            // Return 200 OK so Slack doesn't retry
            return response()->json(['skipped' => true, 'code' => $e->validator->errors()->first()]);
        }

        $event = $request->input('event');
        $incomingMessage = Slack::createIncomingMessageFrom(event: $event, attachments: $this->attachments);
        $thread = Thread::forMessage($incomingMessage);

        // Acknowledge the incoming message
        $this->connector->thumbsUp($event);

        // Handle commands
        if ($command = $this->commands->match($incomingMessage->text ?? '')) {
            $command->handle($incomingMessage, $thread);

            return response()->json(['success' => true]);
        }

        // We need references to pass to the callback
        $attachments = $this->attachments;
        $approvals = $this->approvals;

        $agent = resolve(ChatBotAgent::class, ['message' => $incomingMessage, 'thread' => $thread]);

        // When the agent paused on a gated tool call, this event is the user's
        // answer to it and resumes the paused run instead of starting a new turn.
        $decisions = $approvals->decisionsFrom($thread, $incomingMessage);

        // Queue the agent response, on callback deliver the response via the correct connector
        ($decisions ? $agent->queue($decisions) : $agent->queue(...$incomingMessage->toAgentInput()))
            ->then(function (AgentResponse $response) use ($thread, $incomingMessage, $attachments, $approvals): void {
                $thread->update(['conversation_id' => $response->conversationId]);

                $thread->connector()->reply(
                    thread: $thread,
                    text: $approvals->capture($thread, $response) ?? $response->text,
                    attachments: $attachments->outbound($incomingMessage->uuid)->getAll(),
                );
            })
            ->catch(function (Throwable $e) use ($thread, $approvals): void {
                // A stale resume leaves the thread pointing at a pause the conversation
                // no longer has. Clear it, or every later message reads as an answer.
                if ($notice = $approvals->recover($thread, $e)) {
                    $thread->connector()->reply($thread, $notice);

                    return;
                }

                Log::error('Slack agent error', ['error' => $e->getMessage()]);
            });

        return response()->json(['success' => true]);
    }
}
