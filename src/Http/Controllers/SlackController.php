<?php

namespace LaraClaw\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Redis;
use LaraClaw\Channels\SlackChannel;
use LaraClaw\Jobs\ProcessMessage;

class SlackController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! config('laraclaw.channels.slack.enabled')) {
            return response()->json(['ok' => true]);
        }

        // URL verification challenge
        if ($request->input('type') === 'url_verification') {
            return response()->json(['challenge' => $request->input('challenge')]);
        }

        if ($request->input('type') !== 'event_callback') {
            return response()->json(['ok' => true]);
        }

        $event = $request->input('event', []);

        // Only handle message events
        if (($event['type'] ?? null) !== 'message') {
            return response()->json(['ok' => true]);
        }

        // Filter out bot messages and all subtypes except file_share
        $subtype = $event['subtype'] ?? null;
        if (isset($event['bot_id']) || ($subtype !== null && $subtype !== 'file_share')) {
            return response()->json(['ok' => true]);
        }

        $channelId = $event['channel'] ?? null;
        $text = $event['text'] ?? null;

        if (! $channelId) {
            return response()->json(['ok' => true]);
        }

        $identifier = "slack:{$channelId}";

        // If a tool is waiting for confirmation, push the reply and return early
        if (Redis::exists("awaiting_confirm:{$identifier}")) {
            if ($text !== null) {
                Redis::rpush("confirm:{$identifier}", $text);
            }

            return response()->json(['ok' => true]);
        }

        $channel = SlackChannel::fromEvent($event);

        if (blank($channel->text()) && $channel->attachments()->isEmpty()) {
            return response()->json(['ok' => true]);
        }

        if (! $channel->shouldRespond()) {
            return response()->json(['ok' => true]);
        }

        ProcessMessage::dispatch($channel);

        return response()->json(['ok' => true]);
    }
}
