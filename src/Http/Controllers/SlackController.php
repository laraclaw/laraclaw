<?php

namespace Laraclaw\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Laraclaw\Commands\CommandRegistry;
use Laraclaw\Connectors\Slack;
use Laraclaw\Jobs\RunAgentTurn;
use Laraclaw\Models\Thread;
use Laraclaw\Services\Attachments;

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

        // Acknowledge the incoming message
        $this->connector->thumbsUp($event);

        // Handle commands
        if ($command = $this->commands->match($incomingMessage->text ?? '')) {
            $command->handle($incomingMessage, $thread);

            return response()->json(['success' => true]);
        }

        // Hand the turn to the queue. The agent is built inside the job so it
        // reads the conversation the previous turn saved, and the job holds a
        // lock on the thread so two messages sent at once run one after the other.
        RunAgentTurn::dispatch($thread, $incomingMessage);

        return response()->json(['success' => true]);
    }
}
