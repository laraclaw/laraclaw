<?php

namespace Laraclaw\Services;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Laraclaw\Exceptions\OutboundRequestBlocked;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * The single gate every outbound HTTP request an agent tool makes has to pass.
 *
 * Guzzle's own redirect handling is switched off and the chain is walked here
 * instead. Its on_redirect callback is handed the request that produced the
 * redirect, not the one about to be sent, so a check written against that
 * argument validates a URL we already cleared and lets the destination through.
 * Walking the chain by hand means every hop is validated as a fresh target and
 * every hop can be pinned to the address that was validated.
 */
class OutboundRequestPolicy
{
    public const string BLOCKED_MESSAGE = 'Requests to private/internal network addresses are not allowed.';

    private const array ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Loopback, private, shared address space, link local, benchmarking,
     * documentation, multicast and reserved IPv4 ranges.
     */
    private const array BLOCKED_IPV4 = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
    ];

    /**
     * Unspecified, loopback, NAT64, discard, documentation, unique local,
     * link local and multicast IPv6 ranges. Addresses that merely wrap an IPv4
     * address are unwrapped before this list is consulted, see normalize().
     */
    private const array BLOCKED_IPV6 = [
        '::/128',
        '::1/128',
        '64:ff9b::/96',
        '100::/64',
        '2001:db8::/32',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    /**
     * Send a request, validating the target and the destination of every redirect.
     *
     * The configure callback is handed the pending request and the method for the
     * current hop, so a caller can attach a body only where it still applies. It
     * runs again for each hop because a redirect is a brand new request.
     *
     * @param  callable(PendingRequest, string): PendingRequest|null  $configure
     */
    public function send(string $method, string $url, int $timeout, ?callable $configure = null): Response
    {
        $method = strtolower($method);
        $hops = 0;
        $limit = (int) config('laraclaw.http.max_redirects', 5);

        while (true) {
            $pending = $this->client($url, $timeout);

            if ($configure !== null) {
                $pending = $configure($pending, $method);
            }

            $response = $pending->{$method}($url);
            $location = (string) $response->header('Location');

            if (! $response->redirect() || $location === '') {
                return $response;
            }

            if ($hops >= $limit) {
                throw new OutboundRequestBlocked("Gave up after following {$limit} redirects.");
            }

            $hops++;

            // A Location header is allowed to be relative, so it is resolved
            // against the URL of the hop that sent it before being validated.
            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
            $method = $this->methodAfterRedirect($method, $response->status());
        }
    }

    /**
     * Stream a validated URL into a temporary file and return that file's path.
     *
     * The body is never held in memory and the transfer is cut off as soon as it
     * grows past the cap, so a hostile server can exhaust neither memory nor disk.
     * The caller owns the returned file and is responsible for removing it.
     */
    public function download(string $url, int $timeout, int $maxBytes): string
    {
        $sink = tempnam(sys_get_temp_dir(), 'laraclaw-download-');

        if ($sink === false) {
            throw new OutboundRequestBlocked('Could not open a temporary file to download into.');
        }

        try {
            $response = $this->send('get', $url, $timeout, fn (PendingRequest $pending): PendingRequest => $pending
                ->sink($sink)
                ->withOptions(['progress' => $this->abortPastCap($maxBytes)]));
        } catch (Throwable $e) {
            // curl may wrap whatever the progress callback threw, so the size of
            // what reached disk is the reliable signal for why the transfer died.
            $wasOverCap = $this->overCap($sink, $maxBytes);

            $this->discard($sink);

            throw $wasOverCap ? new OutboundRequestBlocked($this->capMessage($maxBytes)) : $e;
        }

        if (! $response->successful()) {
            $this->discard($sink);

            throw new OutboundRequestBlocked("Failed to download URL (HTTP {$response->status()}): {$url}");
        }

        if ($this->overCap($sink, $maxBytes)) {
            $this->discard($sink);

            throw new OutboundRequestBlocked($this->capMessage($maxBytes));
        }

        return $sink;
    }

    /**
     * Build a client for one hop, with the destination validated and the
     * connection pinned to the address that was validated.
     */
    public function client(string $url, int $timeout): PendingRequest
    {
        $addresses = $this->validate($url);

        return Http::timeout($timeout)
            ->withoutRedirecting()
            ->withOptions($this->pinnedResolution($url, $addresses));
    }

    /**
     * Check the scheme and resolve the host, refusing anything that points at a
     * private, loopback, link local or otherwise reserved address.
     *
     * Every address the host answers with is checked, not just the first, so a
     * public answer cannot hide a private one behind it.
     *
     * @return string[] the addresses the host resolved to
     */
    public function validate(string $url): array
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new OutboundRequestBlocked("Invalid URL: {$url}");
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new OutboundRequestBlocked("Only http and https URLs are allowed, got \"{$scheme}\".");
        }

        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');

        if ($host === '') {
            throw new OutboundRequestBlocked("Could not read a host from {$url}.");
        }

        $addresses = $this->resolve($host);

        if ($addresses === []) {
            throw new OutboundRequestBlocked("Could not resolve the host {$host}.");
        }

        if (collect($addresses)->contains(fn (string $address): bool => $this->isBlockedIp($address))) {
            throw new OutboundRequestBlocked(self::BLOCKED_MESSAGE);
        }

        return $addresses;
    }

    /**
     * Return true when the address falls inside any range the agent must not reach.
     */
    public function isBlockedIp(string $ip): bool
    {
        $ip = $this->normalize($ip);

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        return IpUtils::checkIp($ip, filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ? self::BLOCKED_IPV4
            : self::BLOCKED_IPV6);
    }

    /**
     * Unwrap IPv4 addresses that were written in IPv6 form.
     *
     * Without this, ::ffff:127.0.0.1 is checked against the IPv6 ranges only,
     * misses every one of them and sails through as a public address.
     */
    private function normalize(string $ip): string
    {
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        $mapped = str_repeat("\0", 10) . "\xff\xff";
        $compatible = str_repeat("\0", 12);
        $prefix = substr($packed, 0, 12);

        // Anything that is not one of the two well known wrapping prefixes is a
        // genuine IPv6 address and has to stay one.
        if ($prefix !== $mapped && $prefix !== $compatible) {
            return $ip;
        }

        $unwrapped = inet_ntop(substr($packed, 12));

        return $unwrapped === false ? $ip : $unwrapped;
    }

    /**
     * Resolve a host to every address it answers with.
     *
     * @return string[]
     */
    private function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $records = collect(@dns_get_record($host, DNS_A) ?: [])
            ->pluck('ip')
            ->merge(collect(@dns_get_record($host, DNS_AAAA) ?: [])->pluck('ipv6'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($records !== []) {
            return $records;
        }

        // dns_get_record can come back empty for hosts a plain resolver still
        // knows about, such as anything served out of /etc/hosts.
        $resolved = gethostbyname($host);

        return $resolved === $host ? [] : [$resolved];
    }

    /**
     * Hand curl the full set of addresses this host was validated against, so a
     * second DNS answer cannot swap in a private host between the check and the
     * connect, which is the DNS rebinding attack validation alone does not stop.
     *
     * Every address in the list has already passed isBlockedIp(), so pinning all
     * of them rather than picking one keeps failover working for a host with
     * several public addresses without widening what we are willing to reach.
     *
     * curl keeps using the original hostname for the Host header and the TLS
     * handshake, so only the address lookup is short circuited. Handlers other
     * than curl ignore the option and fall back to validation alone.
     */
    private function pinnedResolution(string $url, array $addresses): array
    {
        if (! defined('CURLOPT_RESOLVE') || $addresses === []) {
            return [];
        }

        $parts = parse_url($url) ?: [];
        $host = trim((string) ($parts['host'] ?? ''), '[]');

        if ($host === '') {
            return [];
        }

        // parse_url leaves the scheme cased exactly as it was written, and
        // validate() accepts "HTTPS://" just as happily as "https://", so the
        // default port has to be chosen from the lowered form or the pin lands
        // on port 80, never matches, and curl quietly resolves the host itself.
        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return ['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:" . implode(',', $addresses)]]];
    }

    /**
     * Work out which method the next hop uses.
     *
     * A 303, and by long settled browser behaviour a 301 or 302 too, turns
     * anything other than HEAD into a GET. A 307 or 308 keeps the method.
     */
    private function methodAfterRedirect(string $method, int $status): string
    {
        if ($method === 'head' || in_array($status, [307, 308], true)) {
            return $method;
        }

        return 'get';
    }

    /**
     * Build a Guzzle progress callback that kills the transfer the moment the
     * body grows past the cap, so we never write more than we agreed to accept.
     */
    private function abortPastCap(int $maxBytes): callable
    {
        return function (int $downloadTotal, int $downloadedBytes) use ($maxBytes): void {
            if ($downloadTotal > $maxBytes || $downloadedBytes > $maxBytes) {
                throw new OutboundRequestBlocked($this->capMessage($maxBytes));
            }
        };
    }

    /**
     * Return true when what landed on disk is bigger than the cap allows.
     */
    private function overCap(string $sink, int $maxBytes): bool
    {
        clearstatcache(true, $sink);

        return file_exists($sink) && filesize($sink) > $maxBytes;
    }

    /**
     * Remove a partial download.
     */
    private function discard(string $sink): void
    {
        if (file_exists($sink)) {
            unlink($sink);
        }
    }

    /**
     * Describe the size cap in a way the agent can report back to the user.
     */
    private function capMessage(int $maxBytes): string
    {
        return 'The download was refused because it exceeds the ' . round($maxBytes / 1024 / 1024, 1) . 'MB limit.';
    }
}
