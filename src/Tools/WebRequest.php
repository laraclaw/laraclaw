<?php

namespace LaraClaw\Tools;

use Exception;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Stringable;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Agent tool for making outbound HTTP requests (GET, POST, PUT, PATCH, DELETE, HEAD).
 */
class WebRequest extends BaseTool
{
    private const TIMEOUT = 15;

    private const MAX_RESPONSE_BYTES = 100 * 1024;

    /**
     * Return the tool description shown to the agent.
     */
    public function description(): Stringable|string
    {
        return 'Make HTTP requests. Operations: ' . implode(', ', $this->operations())
            . '. Returns status code, headers, and body (truncated to 100KB). To browse a website, prefer fetching https://markdown.new/{url} to get clean markdown instead of raw HTML.';
    }

    /**
     * Define the input schema for this tool.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->required()->description('HTTP method: ' . implode(', ', $this->operations())),
            'url' => $schema->string()->required()->description('The URL to request'),
            'headers' => $schema->object()->description('Request headers as key-value pairs'),
            'body' => $schema->string()->description('Request body (JSON string) for POST/PUT/PATCH'),
        ];
    }

    /**
     * Validate the URL and block private network addresses, then dispatch to the operation.
     */
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

    /**
     * Return the list of supported operation names.
     */
    protected function operations(): array
    {
        return ['get', 'head', 'post', 'put', 'patch', 'delete'];
    }

    // Operations

    /**
     * Send a GET request and return a formatted response.
     */
    protected function get(Request $request): string
    {
        return $this->formatResponse($this->send('get', $request));
    }

    /**
     * Send a HEAD request and return the status code and headers.
     */
    protected function head(Request $request): string
    {
        $response = $this->send('head', $request);

        return json_encode([
            'status' => $response->status(),
            'headers' => $this->summarizeHeaders($response->headers()),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Send a POST request and return a formatted response.
     */
    protected function post(Request $request): string
    {
        return $this->formatResponse($this->send('post', $request));
    }

    /**
     * Send a PUT request and return a formatted response.
     */
    protected function put(Request $request): string
    {
        return $this->formatResponse($this->send('put', $request));
    }

    /**
     * Send a PATCH request and return a formatted response.
     */
    protected function patch(Request $request): string
    {
        return $this->formatResponse($this->send('patch', $request));
    }

    /**
     * Send a DELETE request and return a formatted response.
     */
    protected function delete(Request $request): string
    {
        return $this->formatResponse($this->send('delete', $request));
    }

    // Helpers

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
            $body = substr($body, 0, self::MAX_RESPONSE_BYTES) . "\n\n[Truncated: response exceeds 100KB]";
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
     * Resolves ALL DNS records so a single public IP answer cannot hide a private
     * one behind it (DNS rebinding defence). Every redirect URL is also checked, see send().
     */
    private function isPrivateUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if ($host === null || $host === '') {
            return true;
        }

        // Strip IPv6 brackets before DNS lookups
        $host = trim($host, '[]');

        $ips = collect(dns_get_record($host, DNS_A) ?: [])
            ->pluck('ip')
            ->merge(collect(dns_get_record($host, DNS_AAAA) ?: [])->pluck('ipv6'))
            ->filter()
            ->values()
            ->all();

        // No DNS records found, which may mean this is a raw IP literal. Fall back to gethostbyname().
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
     * Return true if $ip falls within any loopback, private, or reserved range.
     */
    private function isPrivateIp(string $ip): bool
    {
        return IpUtils::checkIp($ip, [
            '127.0.0.0/8',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '169.254.0.0/16',
            '0.0.0.0',
            '::1/128',
            'fc00::/7',
            'fe80::/10',
        ]);
    }
}
