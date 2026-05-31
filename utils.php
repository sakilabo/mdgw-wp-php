<?php
// HTTP status codes
const HTTP_SEE_OTHER             = 303;
const HTTP_BAD_REQUEST           = 400;
const HTTP_INTERNAL_SERVER_ERROR = 500;
const HTTP_BAD_GATEWAY           = 502;

// Exception used to abort processing and return an error response. Carries the HTTP status in `code`.
class HttpException extends \Exception
{
    public function __construct(string $message, int $status = HTTP_INTERNAL_SERVER_ERROR)
    {
        parent::__construct($message, $status);
    }
}

// Internationalization helpers (client_lang(), t()) and the message catalog.
require_once __DIR__ . '/i18n.php';

// Set up the error response for uncaught exceptions.
set_exception_handler(function (\Throwable $e): void {
    if ($e instanceof HttpException) {
        // For an HttpException, output its status code and message as-is
        $status  = $e->getCode();
        $message = $e->getMessage();
    } else {
        // For other exceptions, hide the error details (internal information)
        $status  = HTTP_INTERNAL_SERVER_ERROR;
        $message = t('server_error');
    }
    // Output the error response
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo $message . "\n";
});

// Return the configuration (associative array).
// Loads config.php on the first call and caches it for subsequent calls.
function load_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }
    // Abort if the file does not exist
    if (!file_exists(__DIR__ . '/config.php')) {
        throw new HttpException(t('config_missing'));
    }
    // Load the configuration file
    $config = require __DIR__ . '/config.php';
    // Abort if it is not an array
    if (!is_array($config)) {
        throw new HttpException(t('config_invalid'));
    }
    // Abort if a required setting is missing
    foreach (['wp_site'] as $key) {
        if (!isset($config[$key]) || $config[$key] === '') {
            throw new HttpException(t('config_key_missing', $key));
        }
    }
    // Normalize wp_site up front
    $normalized = normalize_url($config['wp_site']);
    if ($normalized === null) {
        throw new HttpException(t('config_wp_site_invalid'));
    }
    $config['wp_site'] = $normalized;
    // Return the configuration
    return $config;
}

// Return true if the user agent string likely belongs to an AI agent.
function is_agent(string $user_agent): bool
{
    if ($user_agent === '-') {
        return true;
    }
    $ai_agents_words = [
        'anthropic',
        'claude',
        'google',
        'gemini',
        'openai',
        'chatgpt',
        'perplexity',
        'bing',
        'facebook',
        'curl',
    ];
    foreach ($ai_agents_words as $word) {
        if (stripos($user_agent, $word) !== false) {
            return true;
        }
    }
    return false;
}

// Decode punycode and URL encoding for human readability.
function prettify_url(string $url): string
{
    $url = urldecode($url);
    $parts = parse_url($url);
    if (isset($parts['host']) && strpos($parts['host'], 'xn--') !== false) {
        $host = idn_to_utf8($parts['host'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($host !== false) {
            $url = str_replace($parts['host'], $host, $url);
        }
    }
    return $url;
}

// Normalize a URL: lowercase scheme/host, punycode the host, strip a trailing slash from the path.
// Returns null for relative URLs or unsupported schemes.
function normalize_url(string $url, bool $without_params = false): ?string
{
    $parts = parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }
    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }
    $ascii = idn_to_ascii($parts['host'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
    $host  = strtolower($ascii !== false ? $ascii : $parts['host']);
    $port  = isset($parts['port']) ? ':' . $parts['port'] : '';
    $path  = rtrim($parts['path'] ?? '', '/');
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $fragment  = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
    if ($without_params) {
        return $scheme . '://' . $host . $port . $path;
    } else {
        return $scheme . '://' . $host . $port . $path . $query . $fragment;
    }
}

// Build an absolute URL on this gateway from a relative URL, using the current request.
// Handles a TLS-terminating proxy in front (e.g. nginx -> Apache).
function get_absolute_url(string $relative_url): string
{
    $scheme = (
        ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443'
    ) ? 'https' : 'http';
    $base_url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '')
        . rtrim(strtok($_SERVER['REQUEST_URI'] ?? '/', '?'), '/') . '/';
    return $base_url . ltrim($relative_url, '/');
}

// Returns true if the value is a (delimited) regex pattern, false if it is a plain string.
function is_regex(string $value): bool
{
    return $value !== ''
        // A regex delimiter is always an ASCII symbol; a non-ASCII start (e.g. Japanese) is a plain string.
        && preg_match('/^[[:punct:]]/', $value) === 1
        // Treat as a pattern only when it is valid as PCRE.
        && @preg_match($value, '') !== false;
}

// Build the WP REST API collection endpoint URL for a given post type (rest_base) and page.
function build_collection_endpoint_url(string $wp_site, string $rest_base, int $page): string
{
    return $wp_site . '/wp-json/wp/v2/' . $rest_base
        . '?per_page=100&page=' . $page . '&_fields=id,title,link,date,date_gmt';
}

// Extract the embedded terms of a post grouped by taxonomy: ['category' => ['News'], 'case_tag' => ['PLC', ...]].
// Returns [] when no terms are embedded (the post was fetched without _embed=wp:term, or has no terms).
function extract_terms_by_taxonomy(array $post): array
{
    $terms_by_tax = [];
    // _embedded['wp:term'] is an array of term groups, one group per taxonomy.
    foreach ($post['_embedded']['wp:term'] ?? [] as $group) {
        foreach ($group as $term) {
            // Key by the term's own taxonomy so custom taxonomies (e.g. case_tag) work without hardcoding.
            $tax = $term['taxonomy'] ?? '';
            if ($tax !== '' && isset($term['name'])) {
                $terms_by_tax[$tax][] = $term['name'];
            }
        }
    }
    return $terms_by_tax;
}

// Build the value for the CSS font-family property from an array of font names.
function build_css_font_family(mixed $fonts): string
{
    $parts = [];
    $has_sans_serif = false;
    // Accept any value; iterate only when an actual array is given (config may omit font_family).
    if (is_array($fonts)) {
        foreach ($fonts as $font) {
            $font = trim((string) $font);
            if ($font === '') {
                continue;
            }
            if ($font === 'sans-serif') {
                $has_sans_serif = true;
            }
            // Quote multi-word family names (e.g. Segoe UI -> "Segoe UI"); leave single identifiers
            // and generic families (sans-serif, system-ui, -apple-system) unquoted.
            if (strpbrk($font, " \t") !== false) {
                $parts[] = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $font) . '"';
            } else {
                $parts[] = $font;
            }
        }
    }
    // Append sans-serif as a final fallback if the list doesn't already include it.
    if (!$has_sans_serif) {
        $parts[] = 'sans-serif';
    }
    return implode(', ', $parts);
}

// Format a value for a YAML front-matter line: a flow sequence for an array, otherwise a
// scalar double-quoted (and escaped) only when YAML would misread it as something else.
function format_yaml_value(string|array $value): string
{
    if (is_array($value)) {
        return $value === [] ? '[]' : '[ ' . implode(', ', array_map('format_yaml_value', $value)) . ' ]';
    }
    $needs_quote =
        $value === ''
        || preg_match('/\s/u', $value)                                     // any whitespace (incl. internal space)
        || str_contains($value, ':')                                        // any colon (e.g. 00:00)
        || preg_match('/(?:^|\s)#/', $value)                                // "#" starting a comment
        || preg_match('/[\[\]{}&*!|>\'"%@`,]/u', $value)                    // indicator / flow chars
        || preg_match('/^[-?:]/', $value)                                   // leading indicator
        || preg_match('/^(?:true|false|null|yes|no|on|off|~)$/i', $value)   // type-coerced word
        || preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)$/', $value);              // number-looking
    if (!$needs_quote) {
        return $value;
    }
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
}

// Format a WordPress "date_gmt" (an ISO-8601 datetime in UTC) for display: convert it to the
// target timezone and append the numeric offset, e.g. "2026-05-31 12:00:00 +09:00".
function format_datetime(string $gmt, ?string $timezone = null): string
{
    if ($gmt === '') {
        return '';
    }
    // Target zone: the configured one, or this server's default; an invalid value falls back too.
    try {
        $tz = new DateTimeZone($timezone ?? date_default_timezone_get());
    } catch (\Exception $e) {
        $tz = new DateTimeZone(date_default_timezone_get());
    }
    // Interpret the input as UTC (date_gmt carries no offset), then convert to the target zone.
    try {
        $dt = new DateTime($gmt, new DateTimeZone('UTC'));
    } catch (\Exception $e) {
        return ''; // unparseable input
    }
    return $dt->setTimezone($tz)->format('Y-m-d H:i:s P');
}
