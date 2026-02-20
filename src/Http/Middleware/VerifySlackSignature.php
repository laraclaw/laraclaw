<?php

namespace LaraClaw\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifySlackSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $timestamp = $request->header('X-Slack-Request-Timestamp');
        $signature = $request->header('X-Slack-Signature');

        if (! $timestamp || ! $signature) {
            abort(403, 'Missing Slack signature headers.');
        }

        // Reject requests older than 5 minutes to prevent replay attacks
        if (abs(time() - (int) $timestamp) > 300) {
            abort(403, 'Slack request timestamp is too old.');
        }

        $signingSecret = config('laraclaw.slack.signing_secret');
        $baseString = "v0:{$timestamp}:{$request->getContent()}";
        $computedSignature = 'v0='.hash_hmac('sha256', $baseString, $signingSecret);

        if (! hash_equals($computedSignature, $signature)) {
            abort(403, 'Invalid Slack signature.');
        }

        return $next($request);
    }
}
