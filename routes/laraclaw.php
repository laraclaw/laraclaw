<?php

use Illuminate\Support\Facades\Route;
use LaraClaw\Http\Controllers\SlackController;
use SergiX44\Nutgram\Nutgram;

if (config('laraclaw.channels.telegram.enabled')) {
    Route::post('telegram/webhook', fn (Nutgram $bot) => $bot->run());
}

if (config('laraclaw.channels.slack.enabled')) {
    Route::post('slack/webhook', SlackController::class)->middleware('slack.signature');
}
