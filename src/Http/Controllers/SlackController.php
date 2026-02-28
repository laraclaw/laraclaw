<?php

namespace LaraClaw\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaraClaw\Channels\SlackChannel;
use LaraClaw\Jobs\ProcessMessage;
use LaraClaw\Message;

class SlackController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if ($request->input('type') === 'url_verification') {
            return response()->json(['challenge' => $request->input('challenge')]);
        }

        if (! $this->shouldHandle($request)) {
            return $this->ok();
        }

        $message = SlackChannel::parseIncomingMessage($request->input('event', []));

        if ($this->shouldDispatch($message)) {
            ProcessMessage::dispatch($message);
        }

        return $this->ok();
    }

    private function shouldHandle(Request $request): bool
    {
        $event = $request->input('event', []);
        $subtype = $event['subtype'] ?? null;

        return config('laraclaw.channels.slack.enabled')
            && $request->input('type') === 'event_callback'
            && ($event['type'] ?? null) === 'message'
            && empty($event['bot_id'])
            && in_array($subtype, [null, 'file_share'], true)
            && filled($event['channel'] ?? null);
    }

    private function shouldDispatch(Message $message): bool
    {
        return ! $message->channel->intercept($message->text)
            && (filled($message->text) || $message->attachments->isNotEmpty())
            && ! $message->shouldBeIgnored();
    }

    private function ok(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
