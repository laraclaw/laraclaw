<?php

namespace Laraclaw\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that verifies the secret token Telegram echoes back on every webhook call.
 */
class VerifyTelegramSecret
{
    private const string HEADER = 'X-Telegram-Bot-Api-Secret-Token';

    /**
     * Compare the header against the configured secret and reject everything else.
     *
     * Telegram returns the value passed to setWebhook in this header on every
     * request, so a caller that cannot produce it did not come from Telegram.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('laraclaw.connectors.telegram.secret_token');

        // Without a secret we cannot tell Telegram apart from anyone who found
        // the URL, and a group message runs tools as the owner, so we refuse
        // rather than wave the request through.
        if (blank($secret)) {
            abort(403, 'Telegram secret token is not configured.');
        }

        $header = $request->header(self::HEADER);

        if ($header === null || ! hash_equals((string) $secret, (string) $header)) {
            abort(403, 'Invalid Telegram secret token.');
        }

        return $next($request);
    }
}
