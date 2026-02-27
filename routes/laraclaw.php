<?php

use Illuminate\Support\Facades\Route;
use LaraClaw\Handlers\Slack;
use SergiX44\Nutgram\Nutgram;

if (config('laraclaw.channels.telegram.enabled') && class_exists(Nutgram::class)) {
    Route::post('telegram/webhook', fn (Nutgram $bot) => $bot->run());
}

if (config('laraclaw.channels.slack.enabled')) {
    Route::post('slack/webhook', Slack::class)->middleware('slack.signature');
}
