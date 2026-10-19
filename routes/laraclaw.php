<?php

use Illuminate\Support\Facades\Route;
use Laraclaw\Events\TelegramMessageReceived;
use Laraclaw\Http\Controllers\ApiController;
use Laraclaw\Http\Controllers\SlackController;
use Telegram\Bot\Api;

if (config('laraclaw.connectors.telegram.enabled')) {
    Route::post('telegram/webhook', function (Api $bot) {
        $update = $bot->getWebhookUpdate();
        $message = $update->getMessage();

        if ($message) {
            event(new TelegramMessageReceived($message, $bot));
        }
    })->middleware(['telegram.secret', 'throttle:laraclaw-telegram']);
}

if (config('laraclaw.connectors.slack.enabled')) {
    Route::post('slack/webhook', SlackController::class)
        ->middleware(['slack.signature', 'throttle:laraclaw-slack']);
}

if (config('laraclaw.connectors.api.enabled')) {
    Route::post('api/message', ApiController::class)
        ->middleware(['laraclaw.api', 'throttle:laraclaw-api']);
}
