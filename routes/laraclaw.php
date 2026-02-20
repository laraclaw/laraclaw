<?php

use LaraClaw\Handlers\Slack;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;
use SergiX44\Nutgram\Nutgram;

Route::middleware('web')->group(function () {
    if (class_exists(Nutgram::class)) {
        Route::post('/telegram/webhook', fn (Nutgram $bot) => $bot->run())
            ->withoutMiddleware(ValidateCsrfToken::class);
    }

    Route::post('/slack/webhook', Slack::class)
        ->middleware('slack.signature')
        ->withoutMiddleware(ValidateCsrfToken::class);
});
