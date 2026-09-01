<?php

namespace Laraclaw\Tools;

use Exception;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Exceptions\OutboundRequestBlocked;
use Laraclaw\Services\OutboundRequestPolicy;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

/**
 * Agent tool for making outbound HTTP requests (GET, POST, PUT, PATCH, DELETE, HEAD).
 */
class WebRequest extends BaseTool
{
    private const int TIMEOUT = 15;

    private const MAX_RESPONSE_BYTES = 100 * 1024;

    /**
     * Bind the inbound message and the outbound request policy every hop is checked against.
     */
    public function __construct(
        protected IncomingMessage $message,
        private readonly OutboundRequestPolicy $policy = new OutboundRequestPolicy,
    ) {}

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
     * Dispatch to the operation, turning a refused or failed request into a
     * message the agent can read rather than an exception that kills the run.
     */
    #[Override]
    public function handle(Request $request): Stringable|string
    {
        try {
            return parent::handle($request);
        } catch (OutboundRequestBlocked $e) {
            return $e->getMessage();
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
     * Execute the request through the outbound policy, which validates the target
     * and every redirect destination before anything leaves the machine.
     */
    private function send(string $method, Request $request): Response
    {
        $headers = $request['headers'] ?? null;
        $body = $request['body'] ?? null;

        return $this->policy->send($method, $request['url'], self::TIMEOUT, function (PendingRequest $pending, string $hop) use ($headers, $body): PendingRequest {
            if (is_array($headers) && $headers !== []) {
                $pending = $pending->withHeaders($headers);
            }

            // A redirect off a POST usually becomes a GET, so the body is only
            // reattached on the hops that still carry one.
            if ($body !== null && in_array($hop, ['post', 'put', 'patch'], true)) {
                return $pending->withBody($body, $this->detectContentType($body));
            }

            return $pending;
        });
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
            ->filter(fn ($v, $k): bool => in_array(strtolower((string) $k), $keep, true))
            ->map(fn ($v) => is_array($v) ? implode(', ', $v) : $v)
            ->all();
    }
}
