<?php

namespace App\Http\Controllers\Webhooks;

use App\Channels\SlackChannelDriver;
use App\Jobs\ProcessMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SlackWebhookController
{
    public function __invoke(Request $request): JsonResponse|Response
    {
        $payload = $request->all();

        if (($payload['type'] ?? null) === 'url_verification') {
            return response()->json(['challenge' => $payload['challenge']]);
        }

        $event = $payload['event'] ?? [];

        if (($event['type'] ?? null) !== 'message') {
            return response()->noContent();
        }

        // Ignore bot messages to avoid loops
        if (isset($event['bot_id'])) {
            return response()->noContent();
        }

        $driver = SlackChannelDriver::fromEvent($event);

        ProcessMessage::dispatch($driver);

        return response()->noContent();
    }
}
