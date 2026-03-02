<?php

namespace LaraClaw\Tools;

use Exception;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Agent tool for making outbound HTTP requests (GET, POST, PUT, PATCH, DELETE, HEAD).
 */
class WebRequest extends BaseTool
{
    private const TIMEOUT = 15;

    private const MAX_RESPONSE_BYTES = 100 * 1024;

    public function description(): Stringable|string
    {
        return 'Make HTTP requests. Operations: ' . implode(', ', $this->operations())
            . '. Returns status code, headers, and body (truncated to 100KB). To browse a website, prefer fetching https://markdown.new/{url} to get clean markdown instead of raw HTML.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->required()->description('HTTP method: ' . implode(', ', $this->operations())),
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

    protected function operations(): array
    {
        return ['get', 'head', 'post', 'put', 'patch', 'delete'];
    }

    // Operations ----------------------------------------

    /** Send a GET request and return a formatted response. */
    protected function get(Request $request): string
    {
        return $this->formatResponse($this->send('get', $request));
    }

    /** Send a HEAD request and return status code and headers. */
    protected function head(Request $request): string
    {
        $response = $this->send('head', $request);

        return json_encode([
            'status' => $response->status(),
            'headers' => $this->summarizeHeaders($response->headers()),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** Send a POST request and return a formatted response. */
    protected function post(Request $request): string
    {
        return $this->formatResponse($this->send('post', $request));
    }

    /** Send a PUT request and return a formatted response. */
    protected function put(Request $request): string
    {
        return $this->formatResponse($this->send('put', $request));
    }

    /** Send a PATCH request and return a formatted response. */
    protected function patch(Request $request): string
    {
        return $this->formatResponse($this->send('patch', $request));
    }

    /** Send a DELETE request and return a formatted response. */
    protected function delete(Request $request): string
    {
        return $this->formatResponse($this->send('delete', $request));
    }

    // Helpers ----------------------------------------

    /**
     * Execute the HTTP request with redirect safety and optional body/headers.
     */
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

    /**
     * Serialize the response status, headers, and body (truncated if needed) as JSON.
     */
    private function formatResponse(Response $response): string
    {
        $body = $response->body();

        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            $body = substr($body, 0, self::MAX_RESPONSE_BYTES) . "\n\n[Truncated — response exceeds 100KB]";
        }

        return json_encode([
            'status' => $response->status(),
            'headers' => $this->summarizeHeaders($response->headers()),
            'body' => $body,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Detect whether a request body is JSON or plain text.
     */
    private function detectContentType(string $body): string
    {
        json_decode($body);

        return json_last_error() === JSON_ERROR_NONE ? 'application/json' : 'text/plain';
    }

    /**
     * Filter the response headers down to a useful subset.
     */
    private function summarizeHeaders(array $headers): array
    {
        $keep = ['content-type', 'content-length', 'location', 'x-request-id'];

        return collect($headers)
            ->filter(fn ($v, $k) => in_array(strtolower($k), $keep, true))
            ->map(fn ($v) => is_array($v) ? implode(', ', $v) : $v)
            ->all();
    }

    /**
     * Return true if the URL resolves to any private or reserved IP address.
     *
     * Resolves ALL DNS records so a single public-IP answer cannot hide a private
     * one (DNS rebinding defence). Blocks on every redirect URL too — see send().
     */
    private function isPrivateUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if ($host === null || $host === '') {
            return true;
        }

        $host = trim($host, '[]'); // strip IPv6 brackets

        $ips = [];

        foreach (dns_get_record($host, DNS_A) ?: [] as $r) {
            if (isset($r['ip'])) {
                $ips[] = $r['ip'];
            }
        }

        foreach (dns_get_record($host, DNS_AAAA) ?: [] as $r) {
            if (isset($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }

        // No DNS records — may be a raw IP literal; fall back to gethostbyname()
        if (empty($ips)) {
            $resolved = gethostbyname($host);
            $ips[] = $resolved !== $host ? $resolved : $host;
        }

        foreach ($ips as $ip) {
            if ($this->isPrivateIp($ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return true if $ip falls within any loopback, private, or link-local range.
     * Uses binary prefix comparisons via inet_pton() so it handles both IPv4 and IPv6.
     */
    private function isPrivateIp(string $ip): bool
    {
        $packed = inet_pton($ip);

        if ($packed === false) {
            return true; // unparseable — block
        }

        if (strlen($packed) === 4) {
            $n = unpack('N', $packed)[1];

            return
                ($n & 0xFF000000) === 0x7F000000 || // 127.0.0.0/8  loopback
                ($n & 0xFF000000) === 0x0A000000 || // 10.0.0.0/8
                ($n & 0xFFF00000) === 0xAC100000 || // 172.16.0.0/12
                ($n & 0xFFFF0000) === 0xC0A80000 || // 192.168.0.0/16
                ($n & 0xFFFF0000) === 0xA9FE0000 || // 169.254.0.0/16 link-local
                $n === 0x00000000;                   // 0.0.0.0
        }

        // IPv6
        if ($packed === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01") {
            return true; // ::1 loopback
        }

        if ((ord($packed[0]) & 0xFE) === 0xFC) {
            return true; // fc00::/7 unique local
        }

        if (ord($packed[0]) === 0xFE && (ord($packed[1]) & 0xC0) === 0x80) {
            return true; // fe80::/10 link-local
        }

        return false;
    }
}
