<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laraclaw\Http\Middleware\VerifyTelegramSecret;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['laraclaw.connectors.telegram.secret_token' => 'test-secret']);
});

function makeTelegramRequest(?string $secret = null): Request
{
    $server = $secret === null ? [] : ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => $secret];

    return Request::create('/telegram/webhook', 'POST', content: '{"update_id":1}', server: $server);
}

it('passes requests carrying the configured secret through', function () {
    $response = (new VerifyTelegramSecret)->handle(
        makeTelegramRequest('test-secret'),
        fn () => response('ok'),
    );

    expect($response->getContent())->toBe('ok');
});

it('rejects requests with a wrong secret', function () {
    (new VerifyTelegramSecret)->handle(makeTelegramRequest('wrong-secret'), fn () => response('ok'));
})->throws(HttpException::class, 'Invalid Telegram secret token.');

it('rejects requests with no secret header at all', function () {
    (new VerifyTelegramSecret)->handle(makeTelegramRequest(), fn () => response('ok'));
})->throws(HttpException::class, 'Invalid Telegram secret token.');

it('fails closed when no secret is configured', function () {
    config(['laraclaw.connectors.telegram.secret_token' => null]);

    (new VerifyTelegramSecret)->handle(makeTelegramRequest('anything'), fn () => response('ok'));
})->throws(HttpException::class, 'Telegram secret token is not configured.');

it('attaches the middleware to the webhook route', function () {
    config(['laraclaw.connectors.telegram.enabled' => true]);

    // The route file only registers Telegram when the connector is enabled, and
    // the test app boots with it off, so load it again now that it is on.
    require dirname(__DIR__, 4) . '/routes/laraclaw.php';

    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn (\Illuminate\Routing\Route $route): bool => $route->uri() === 'telegram/webhook');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('telegram.secret');
});
