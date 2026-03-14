<?php

namespace LaraClaw\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LaraClaw\Enums\ConnectorType;
use LaraClaw\Models\Account;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify the Bearer token against hashed API tokens stored in the accounts table.
 */
class VerifyApiToken
{
    /**
     * Look up the hashed Bearer token and set the authenticated user on the request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            abort(401, 'Missing API token.');
        }

        $account = Account::with('user')
            ->forConnector(hash('sha256', $token), ConnectorType::Api)
            ->first();

        if (! $account?->user) {
            abort(401, 'Invalid API token.');
        }

        $request->setUserResolver(fn () => $account->user);

        return $next($request);
    }
}
