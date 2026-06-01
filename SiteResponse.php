<?php
declare(strict_types=1);

// Result of a SiteRequest::get() request: the HTTP outcome plus the response body and headers.
final class SiteResponse
{
    public readonly array $headers; // lowercased header name => value, parsed from the raw header block

    public function __construct(
        public readonly bool $success = false,      // true for an HTTP 2xx response
        public readonly int $status = 0,            // HTTP status code (0 when no response was received)
        public readonly string|false $body = false, // response body on success, false otherwise
        string $raw_headers = '',                   // raw HTTP header block; parsed into $headers
        public readonly string $error = '',         // transport error (e.g. curl error); empty when none
    ) {
        $this->headers = self::parseHeaders($raw_headers);
    }

    // Convert a raw HTTP header string into a name => value associative array. Header names are
    // lowercased: HTTP/2 always sends them lowercase while HTTP/1.1 is mixed-case, so callers must
    // look them up by the lowercase name (e.g. $headers['location'], not $headers['Location']).
    private static function parseHeaders(string $raw_headers): array
    {
        $headers = [];
        foreach (explode("\r\n", trim($raw_headers)) as $line) {
            if (strpos($line, ':') !== false) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        return $headers;
    }
}
