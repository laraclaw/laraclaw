<?php

use App\Http\Controllers\Webhooks\SlackWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('webhook/slack', SlackWebhookController::class)
    ->middleware('slack.signature');
