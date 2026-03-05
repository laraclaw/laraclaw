<?php

namespace LaraClaw\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaraClaw\Channels\SlackChannel;
use LaraClaw\Jobs\ProcessMessage;

/**
 * Handles incoming Slack event webhook requests.
 */
class SlackController extends Controller
{
    /**
     * Process a Slack event callback and dispatch a ProcessMessage job if applicable.
     */
    public function __invoke(Request $request): JsonResponse
    {
        if ($request->input('type') === 'url_verification') {
            return response()->json(['challenge' => $request->input('challenge')]);
        }

        $bail = null;
        $event = $request->input('event', []);

        if (! config('laraclaw.channels.slack.enabled')) {
            $bail = 'CHANNEL_DISABLED';
        }

        if ($request->input('type') !== 'event_callback') {
            $bail = 'NOT_CALLBACK_EVENT';
        }

        if (($event['type'] ?? null) !== 'message') {
            $bail = 'NOT_MESSAGE';
        }

        if (! empty($event['bot_id'])) {
            $bail = 'BOT_MESSAGE';
        }

        if (! in_array($event['subtype'] ?? null, [null, 'file_share'], true)) {
            $bail = 'UNSUPPORTED_SUBTYPE';
        }

        if (blank($event['channel'] ?? null)) {
            $bail = 'NO_CHANNEL';
        }

        if ($bail) {
            return $this->bail($bail);
        }

        ProcessMessage::dispatch(SlackChannel::parseIncomingMessage($event));

        return response()->json(['success' => true]);
    }

    /**
     * Return a successful JSON response with a bail code for events we choose not to process.
     */
    private function bail(string $code): JsonResponse
    {
        return response()->json(['success' => true, 'bailed' => true, 'code' => $code]);
    }
}
