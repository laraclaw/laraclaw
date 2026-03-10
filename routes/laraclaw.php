<?php

use Illuminate\Support\Facades\Route;
use LaraClaw\Events\TelegramMessageReceived;
use LaraClaw\Http\Controllers\ApiController;
use LaraClaw\Http\Controllers\SlackController;
use Telegram\Bot\Api;

if (config('laraclaw.channels.telegram.enabled')) {
    Route::post('telegram/webhook', function (Api $bot) {
        $update = $bot->getWebhookUpdate();
        $message = $update->getMessage();

        if ($message) {
            event(new TelegramMessageReceived($message, $bot));
        }
    })->middleware('throttle:laraclaw-telegram');
}

if (config('laraclaw.channels.slack.enabled')) {
    Route::post('slack/webhook', SlackController::class)
        ->middleware(['slack.signature', 'throttle:laraclaw-slack']);
}

if (config('laraclaw.channels.api.enabled')) {
    Route::post('api/message', ApiController::class)
        ->middleware(['auth:sanctum', 'throttle:laraclaw-api']);
}
