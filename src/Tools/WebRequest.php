<?php

namespace LaraClaw\Tools;

use Exception;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Stringable;

class WebRequest extends BaseTool
{
    private const TIMEOUT = 15;

    private const MAX_RESPONSE_BYTES = 100 * 1024;

    protected function operations(): array
    {
        return ['get', 'head', 'post', 'put', 'patch', 'delete'];
    }

    public function description(): Stringable|string
    {
        return 'Make HTTP requests. Operations: '.implode(', ', $this->operations())
            .'. Returns status code, headers, and body (truncated to 100KB). To browse a website, prefer fetching https://markdown.new/{url} to get clean markdown instead of raw HTML.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->required()->description('HTTP method: '.implode(', ', $this->operations())),
            'url' => $schema->string()->required()->description('The URL to request'),
            'headers' => $schema->object()->description('Request headers as key-value pairs'),
            'body' => $schema->string()->description('Request body (JSON string) for POST/PUT/PATCH'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $url = $request['url'] ?? '';

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return "Invalid URL: {$url}";
        }

        if ($this->isPrivateUrl($url)) {
            return 'Requests to private/internal network addresses are not allowed.';
        }

        try {
            return parent::handle($request);
        } catch (Exception $e) {
            return "HTTP request failed: {$e->getMessage()}";
        }
    }

    // Operations ----------------------------------------

    protected function get(Request $request): string
    {
        return $this->formatResponse($this->send('get', $request));
    }

    protected function head(Request $request): string
    {
        $response = $this->send('head', $request);

        return json_encode([
            'status' => $response->status(),
            'headers' => $this->summarizeHeaders($response->headers()),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    protected function post(Request $request): string
    {
        return $this->formatResponse($this->send('post', $request));
    }

    protected function put(Request $request): string
    {
        return $this->formatResponse($this->send('put', $request));
    }

    protected function patch(Request $request): string
    {
        return $this->formatResponse($this->send('patch', $request));
    }

    protected function delete(Request $request): string
    {
        return $this->formatResponse($this->send('delete', $request));
    }

    // Helpers ----------------------------------------

    private function send(string $method, Request $request): Response
    {
        $url = $request['url'];
        $headers = $request['headers'] ?? null;
        $body = $request['body'] ?? null;

        $pending = Http::timeout(self::TIMEOUT)->withOptions([
            'allow_redirects' => [
                'max' => 5,
                'on_redirect' => function ($req) {
                    $redirectUrl = (string) $req->getUri();
                    if ($this->isPrivateUrl($redirectUrl)) {
                        throw new Exception('Redirect to private/internal network address blocked.');
                    }
                },
            ],
        ]);

        if (is_array($headers) && ! empty($headers)) {
            $pending = $pending->withHeaders($headers);
        }

        if ($body !== null && in_array($method, ['post', 'put', 'patch'], true)) {
            $pending = $pending->withBody($body, $this->detectContentType($body));
        }

        return $pending->$method($url);
    }

    private function formatResponse(Response $response): string
    {
        $body = $response->body();

        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            $body = substr($body, 0, self::MAX_RESPONSE_BYTES)."\n\n[Truncated — response exceeds 100KB]";
        }

        return json_encode([
            'status' => $response->status(),
            'headers' => $this->summarizeHeaders($response->headers()),
            'body' => $body,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function detectContentType(string $body): string
    {
        json_decode($body);

        return json_last_error() === JSON_ERROR_NONE ? 'application/json' : 'text/plain';
    }

    private function summarizeHeaders(array $headers): array
    {
        $keep = ['content-type', 'content-length', 'location', 'x-request-id'];

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
