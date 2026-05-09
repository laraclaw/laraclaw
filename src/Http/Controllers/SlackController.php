<?php

namespace Laraclaw\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laraclaw\Agents\ChatBotAgent;
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

        // Check if the event is a reply to a pending confirmation
        if ($this->connector->resolvePendingConfirmation($incomingMessage)) {
            return response()->json(['confirmation_resolved' => true]);
        }

        // Acknowledge the incoming message
        $this->connector->thumbsUp($event);

        // Handle commands
        if ($command = $this->commands->match($incomingMessage->text ?? '')) {
            $command->handle($incomingMessage, $thread);

            return response()->json(['success' => true]);
        }

        // We need a reference to pass to the callback
        $attachments = $this->attachments;

        // Queue the agent response, on callback deliver the response via the correct connector
        resolve(ChatBotAgent::class, ['message' => $incomingMessage, 'thread' => $thread])
            ->queue(...$incomingMessage->toAgentInput())
            ->then(function (AgentResponse $response) use ($thread, $incomingMessage, $attachments): void {
                $thread->update(['conversation_id' => $response->conversationId]);

                $thread->connector()->reply(
                    thread: $thread,
                    text: $response->text,
                    attachments: $attachments->outbound($incomingMessage->uuid)->getAll(),
                );
            })
            ->catch(function (Throwable $e): void {
                Log::error('Slack agent error', ['error' => $e->getMessage()]);
            });

        return response()->json(['success' => true]);
    }
}
