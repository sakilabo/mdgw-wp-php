<?php

declare(strict_types=1);

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/SiteResponse.php';

// A request that fetches one or more same-site URLs and returns the resulting SiteResponse(s).
// Not final: tests subclass this to override execute() and avoid real network access.
class SiteRequest
{
    // Number of transfers allowed to run at once when fetching many URLs (configurable via 'concurrency').
    private const DEFAULT_CONCURRENCY = 4;
    // Hard ceiling on the configured concurrency, to avoid hammering the WordPress site.
    private const MAX_CONCURRENCY = 8;

    // Shared curl_multi handle, reused across execute() calls within one request so connections
    // to the same host stay in its pool and get reused (HTTP keep-alive). Freed at request shutdown.
    private static ?\CurlMultiHandle $mh = null;

    // Fetch one or more same-site URLs in parallel via curl_multi: a string yields one SiteResponse,
    // an array yields an array keyed the same. Throws for any non-same-site URL (SSRF protection).
    public static function get(string|array $url): SiteResponse|array
    {
        // Normalize to a keyed array of URLs; remember whether the caller passed a single URL.
        $single = !is_array($url);
        $urls = $single ? [$url] : $url;

        // Validate every URL up front so we throw before allocating any handle (SSRF protection).
        foreach ($urls as $one) {
            if (!self::isInternalUrl($one)) {
                throw new HttpException(t('url_must_be_internal'), HTTP_BAD_REQUEST);
            }
        }

        // Run the transfers (static:: so a test subclass can override the network layer).
        $results = static::execute($urls);

        return $single ? $results[0] : $results;
    }

    // Run the curl_multi transfers for the given (already-validated) URLs, keyed the same as the input.
    // protected + called via static:: so a test subclass can override it to avoid real network access.
    protected static function execute(array $urls): array
    {
        // Reuse the shared multi handle so connections stay in its pool across calls (keep-alive).
        $mh = self::multiHandle();
        // Register a handle per URL with the multi handle, keyed the same as the input.
        $handles = [];
        foreach ($urls as $key => $one) {
            $handles[$key] = self::createHandle($one);
            curl_multi_add_handle($mh, $handles[$key]);
        }

        // Drive all transfers concurrently until none remain running.
        do {
            $status = curl_multi_exec($mh, $running);
            // Wait for activity; -1 means there is nothing to wait on, so avoid a busy spin.
            if ($running && curl_multi_select($mh) === -1) {
                usleep(10 * 1000);
            }
        } while ($running && $status === CURLM_OK);

        // Harvest each transfer's real result code. For multi handles curl_errno($ch) can report 0
        // even on failure, so read the authoritative CURLE_* code from curl_multi_info_read().
        $codes = [];
        while ($msg = curl_multi_info_read($mh)) {
            $codes[spl_object_id($msg['handle'])] = $msg['result'];
        }

        // Collect each response (a non-zero result code means transport failure), then release the handles.
        $results = [];
        foreach ($handles as $key => $ch) {
            $code = $codes[spl_object_id($ch)] ?? curl_errno($ch);
            $failed = $code !== 0;
            $raw = $failed ? false : curl_multi_getcontent($ch);
            $error = $failed ? sprintf('curl(%d): %s', $code, curl_strerror($code)) : '';
            $results[$key] = self::buildResponse($ch, $raw, $error);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        // Do not close the multi handle: keeping it (and its connection pool) open lets the next
        // execute() in this request reuse the connections (HTTP keep-alive). Freed at request shutdown.
        // curl_multi_close($mh);

        return $results;
    }

    // Lazily create the shared multi handle; reused for the whole request to enable keep-alive (see execute()).
    private static function multiHandle(): \CurlMultiHandle
    {
        if (self::$mh === null) {
            self::$mh = curl_multi_init();
            // Cap how many transfers run at once; curl starts the rest as connections free up.
            $config = static::config();
            $concurrency = (int) ($config['concurrency'] ?? self::DEFAULT_CONCURRENCY);
            $concurrency = max(1, min($concurrency, self::MAX_CONCURRENCY));
            curl_multi_setopt(self::$mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, $concurrency);
        }
        return self::$mh;
    }

    // Configuration accessor (seam): static:: so a test subclass can override it with a fixed config.
    protected static function config(): array
    {
        return load_config();
    }

    // Determine whether a URL is on the same site as wp_site.
    private static function isInternalUrl(string $url): bool
    {
        $config = static::config();
        // Disallow anything other than the same site (SSRF protection)
        $without_params = true;
        $base = normalize_url($config['wp_site'], $without_params);
        $target = normalize_url($url, $without_params);
        $same_site = $base !== null && ($target === $base || str_starts_with($target, $base . '/'));
        return $same_site;
    }

    // Create a configured curl handle for fetching one same-site URL (caller must validate the URL).
    private static function createHandle(string $url): \CurlHandle
    {
        $config = static::config();
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => $config['timeout'] ?? 15,
            CURLOPT_USERAGENT      => 'MD Gateway for WordPress (https://github.com/sakilabo/mdgw-wp-php)',
        ];
        $parts = parse_url($url);
        // CURLOPT_RESOLVE requires a port
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $wp_addr = $config['wp_addr'] ?? '';
        if ($wp_addr !== '') {
            // Pin the host to the configured address instead of resolving it over DNS
            // (e.g. 127.0.0.1 for a same-server WordPress). The address need not match the
            // certificate, so skip SSL verification (documented limitation).
            $opts[CURLOPT_RESOLVE] = [$parts['host'] . ':' . $port . ':' . $wp_addr];
            if ($parts['scheme'] === 'https') {
                $opts[CURLOPT_SSL_VERIFYPEER] = false;
                $opts[CURLOPT_SSL_VERIFYHOST] = 0;
            }
        } else {
            // Resolve in PHP and pin the IP via CURLOPT_RESOLVE so curl skips its own resolver:
            // some hosts (e.g. Xserver php-fcgi) can't start curl's resolver thread
            // ("getaddrinfo() thread failed to start").
            $ips = self::resolveHost($parts['host']);
            // Resolution failed: abort rather than letting curl fall back to its own (possibly failing) resolver.
            if ($ips === []) {
                throw new HttpException(t('host_resolve_failed', $parts['host']), HTTP_BAD_GATEWAY);
            }
            $opts[CURLOPT_RESOLVE] = [$parts['host'] . ':' . $port . ':' . implode(',', $ips)];
        }
        curl_setopt_array($ch, $opts);
        return $ch;
    }

    // Resolve a host to its IPv4/IPv6 addresses. Returns [] if it cannot be resolved.
    // protected so a test subclass can exercise it.
    protected static function resolveHost(string $host): array
    {
        // Cache per host: every request targets the same wp_site, so resolve it only once.
        static $cache = [];
        if (isset($cache[$host])) {
            return $cache[$host];
        }
        // A literal IP needs no resolution.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $cache[$host] = [$host];
        }
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null; // A => ip, AAAA => ipv6
                if ($ip !== null) {
                    $ips[] = $ip;
                }
            }
        }
        // Fall back to a simple A lookup when dns_get_record yields nothing.
        if ($ips === []) {
            $ip = gethostbyname($host); // returns the host unchanged on failure
            if ($ip !== $host) {
                $ips[] = $ip;
            }
        }
        return $cache[$host] = $ips;
    }

    // Build a SiteResponse from a completed curl transfer ($raw is false on transport failure).
    private static function buildResponse(\CurlHandle $ch, string|false $raw, string $error = ''): SiteResponse
    {
        // Transport failure: no response received.
        if ($raw === false) {
            return new SiteResponse(false, 0, false, '', $error);
        }
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status      = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $raw_headers = substr($raw, 0, $header_size);
        $body        = substr($raw, $header_size);
        // Success is an HTTP 2xx status; keep the body only for a successful response.
        $success = $status >= 200 && $status < 300;
        // On a non-2xx status surface the code so callers can report why the fetch failed.
        $error = $success ? '' : ('HTTP ' . $status);
        return new SiteResponse($success, $status, $success ? $body : false, $raw_headers, $error);
    }
}
