<?php

namespace Laraclaw\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Laraclaw\Agents\Dispatcher;
use Laraclaw\Connectors\Slack;
use Laraclaw\Models\Thread;
use Laraclaw\Services\Attachments;

/**
 * Handles incoming Slack event webhook requests.
 */
class SlackController extends Controller
{
    /**
     * Inject the attachment writer, the outbound Slack client and the dispatcher that queues the agent.
     */
    public function __construct(
        private readonly Attachments $attachments,
        private readonly Slack $connector,
        private readonly Dispatcher $dispatcher,
    ) {}

    /**
     * Process a Slack event.
     *
     * Slack retries anything that is not a 200, so a skipped event is still
     * acknowledged, with the reason in the body for whoever is debugging.
     */
    public function __invoke(Request $request): JsonResponse
    {
        if ($request->input('type') === 'url_verification') {
            return response()->json(['challenge' => $request->input('challenge')]);
        }

        try {
            Slack::validateEvent($request);
        } catch (ValidationException $e) {
            return response()->json(['skipped' => true, 'code' => $e->validator->errors()->first()]);
        }

        $event = $request->input('event');
        $message = Slack::createIncomingMessageFrom($event, $this->attachments);

        $this->connector->thumbsUp($event);
        $this->dispatcher->queue($message, Thread::forMessage($message));

        return response()->json(['success' => true]);
    }
}
