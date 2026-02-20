<?php

namespace LaraClaw\Handlers;

use LaraClaw\Channels\SlackChannel;
use LaraClaw\Jobs\ProcessMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class Slack
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! config('laraclaw.slack.enabled')) {
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

        // Filter out bot messages
        if (isset($event['bot_id']) || isset($event['subtype'])) {
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

        ProcessMessage::dispatch($channel);

        return response()->json(['ok' => true]);
    }
}
