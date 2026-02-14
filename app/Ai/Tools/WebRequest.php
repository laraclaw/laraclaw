<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class WebRequest implements Tool
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    private const TIMEOUT = 15;

    private const MAX_RESPONSE_BYTES = 100 * 1024;

    public function description(): Stringable|string
    {
        return 'Make HTTP requests. Methods: ' . implode(', ', self::METHODS) . '. Returns status code, headers, and body (truncated to 100KB). To browse a website, prefer fetching https://markdown.new/{url} to get clean markdown instead of raw HTML.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'method' => $schema->string()->required()->description('HTTP method: ' . implode(', ', self::METHODS)),
            'url' => $schema->string()->required()->description('The URL to request'),
            'headers' => $schema->string()->description('JSON object of request headers'),
            'body' => $schema->string()->description('Request body (JSON string) for POST/PUT/PATCH'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $method = strtolower($request['method']);
        $url = $request['url'];
        $headers = $request['headers'] ?? null;
        $body = $request['body'] ?? null;

        if (! in_array($method, self::METHODS, true)) {
            return "Unknown method '{$method}'. Available: " . implode(', ', self::METHODS);
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return "Invalid URL: {$url}";
        }

        if ($this->isPrivateUrl($url)) {
            return 'Requests to private/internal network addresses are not allowed.';
        }

        Log::info('WebRequest: executing', compact('method', 'url'));

        $pending = Http::timeout(self::TIMEOUT)->withoutVerifying();

        if ($headers !== null) {
            $decoded = json_decode($headers, true);
            if (is_array($decoded)) {
                $pending = $pending->withHeaders($decoded);
            }
        }

        $response = match ($method) {
            'get', 'delete' => $pending->$method($url),
            default => $pending->withBody($body ?? '', $this->detectContentType($body))->$method($url),
        };

        $status = $response->status();
        $responseBody = $response->body();

        if (strlen($responseBody) > self::MAX_RESPONSE_BYTES) {
            $responseBody = substr($responseBody, 0, self::MAX_RESPONSE_BYTES) . "\n\n[Truncated — response exceeds 100KB]";
        }

        return json_encode([
            'status' => $status,
            'headers' => $this->summarizeHeaders($response->headers()),
            'body' => $responseBody,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function detectContentType(?string $body): string
    {
        if ($body === null) {
            return 'application/json';
        }

        json_decode($body);

        return json_last_error() === JSON_ERROR_NONE ? 'application/json' : 'text/plain';
    }

    private function summarizeHeaders(array $headers): array
    {
        $keep = ['content-type', 'content-length', 'location', 'set-cookie', 'x-request-id'];

        return collect($headers)
            ->filter(fn ($v, $k) => in_array(strtolower($k), $keep, true))
            ->map(fn ($v) => is_array($v) ? implode(', ', $v) : $v)
            ->all();
    }

    private function isPrivateUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if ($host === null) {
            return true;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '[::1]'], true)) {
            return true;
        }

        $ip = gethostbyname($host);

        return $ip !== $host && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
