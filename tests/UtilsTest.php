<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../utils.php';
require_once __DIR__ . '/../SiteResponse.php';

/**
 * Tests for the pure functions in utils.php (those with no network or config.php dependency).
 */
final class UtilsTest extends TestCase
{
    // --- normalize_url -------------------------------------------------------

    public function testNormalizeUrlLowercasesSchemeAndHost(): void
    {
        $this->assertSame('https://example.com', normalize_url('HTTPS://Example.COM'));
    }

    public function testNormalizeUrlStripsTrailingSlash(): void
    {
        $this->assertSame('https://example.com/foo', normalize_url('https://example.com/foo/'));
    }

    public function testNormalizeUrlKeepsQueryAndFragmentByDefault(): void
    {
        $this->assertSame(
            'https://example.com/p?a=1#x',
            normalize_url('https://example.com/p?a=1#x')
        );
    }

    public function testNormalizeUrlWithoutParamsDropsQueryAndFragment(): void
    {
        $this->assertSame(
            'https://example.com/p',
            normalize_url('https://example.com/p?a=1#x', true)
        );
    }

    public function testNormalizeUrlConvertsIdnHostToPunycode(): void
    {
        $result = normalize_url('https://日本語.example/path/');
        $this->assertNotNull($result);
        $this->assertStringStartsWith('https://xn--', $result);
        $this->assertStringEndsWith('.example/path', $result);
    }

    public function testNormalizeUrlRejectsRelativeUrl(): void
    {
        $this->assertNull(normalize_url('/just/a/path'));
    }

    public function testNormalizeUrlRejectsNonHttpScheme(): void
    {
        $this->assertNull(normalize_url('ftp://example.com/file'));
    }

    // --- is_regex ------------------------------------------------------------

    public function testIsRegexTrueForDelimitedPatterns(): void
    {
        $this->assertTrue(is_regex('/^wp_/'));
        $this->assertTrue(is_regex('#abc#i'));
        $this->assertTrue(is_regex('/お知らせ/'));
    }

    public function testIsRegexFalseForPlainString(): void
    {
        $this->assertFalse(is_regex('attachment'));
    }

    public function testIsRegexFalseForNonAsciiLeadingString(): void
    {
        // A non-ASCII leading string is treated as a literal for exact matching
        $this->assertFalse(is_regex('お知らせ'));
    }

    public function testIsRegexFalseForEmptyString(): void
    {
        $this->assertFalse(is_regex(''));
    }

    public function testIsRegexFalseForInvalidPattern(): void
    {
        // Starts with a delimiter but is invalid PCRE (no closing delimiter)
        $this->assertFalse(is_regex('/unterminated'));
    }

    // --- SiteResponse header parsing -----------------------------------------

    public function testSiteResponseParsesRawHeaders(): void
    {
        $raw = "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=UTF-8\r\nX-Foo: bar\r\n";
        $headers = (new SiteResponse(true, 200, 'body', $raw))->headers;

        // Header names are lowercased so lookups are case-insensitive (HTTP/2 sends them lowercase).
        $this->assertSame('text/html; charset=UTF-8', $headers['content-type']);
        $this->assertSame('bar', $headers['x-foo']);
        // The status line, which has no colon, is not captured
        $this->assertArrayNotHasKey('HTTP/1.1 200 OK', $headers);
    }

    public function testSiteResponseLowercasesHeaderNames(): void
    {
        // HTTP/2 delivers header names in lowercase, HTTP/1.1 in mixed case. Both must resolve the same.
        $mixed = (new SiteResponse(true, 200, 'body', "X-WP-TotalPages: 3\r\n"))->headers;
        $lower = (new SiteResponse(true, 200, 'body', "x-wp-totalpages: 3\r\n"))->headers;

        $this->assertSame('3', $mixed['x-wp-totalpages']);
        $this->assertSame('3', $lower['x-wp-totalpages']);
    }

    // --- prettify_url --------------------------------------------------------

    public function testPrettifyUrlDecodesPercentEncoding(): void
    {
        $this->assertSame('https://example.com/a b', prettify_url('https://example.com/a%20b'));
    }

    public function testPrettifyUrlDecodesPunycodeHost(): void
    {
        $ascii  = idn_to_ascii('日本語', IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        $pretty = prettify_url("https://{$ascii}.example/");

        $this->assertStringNotContainsString('xn--', $pretty);
        $this->assertStringContainsString('日本語', $pretty);
    }

    // --- build_css_font_family -----------------------------------------------

    public function testBuildCssFontFamilyQuotesOnlyMultiWordNames(): void
    {
        $this->assertSame(
            'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
            build_css_font_family(['system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'])
        );
    }

    public function testBuildCssFontFamilySkipsEmptyEntries(): void
    {
        $this->assertSame('Arial, sans-serif', build_css_font_family(['', 'Arial', '  ', 'sans-serif']));
    }

    public function testBuildCssFontFamilyAppendsSansSerifWhenMissing(): void
    {
        $this->assertSame('Arial, sans-serif', build_css_font_family(['Arial']));
    }

    public function testBuildCssFontFamilyAppendsSansSerifForEmptyList(): void
    {
        $this->assertSame('sans-serif', build_css_font_family([]));
    }

    public function testBuildCssFontFamilyReturnsSansSerifForNonArray(): void
    {
        $this->assertSame('sans-serif', build_css_font_family(null));
        $this->assertSame('sans-serif', build_css_font_family('Arial'));
    }

    // --- format_yaml_value ---------------------------------------------------

    public function testFormatYamlValueLeavesSafeStringPlain(): void
    {
        $this->assertSame('News', format_yaml_value('News'));
        $this->assertSame('item-1', format_yaml_value('item-1'));
    }

    public function testFormatYamlValueQuotesYamlSignificantStrings(): void
    {
        $this->assertSame('"Hello World"', format_yaml_value('Hello World')); // internal space
        $this->assertSame('"Foo: Bar"', format_yaml_value('Foo: Bar'));   // colon needs quoting
        $this->assertSame('"00:00"', format_yaml_value('00:00'));        // any colon, even in a time
        $this->assertSame('"a, b"', format_yaml_value('a, b'));           // flow comma
        $this->assertSame('"# not a comment"', format_yaml_value('# not a comment'));
        $this->assertSame('" spaced "', format_yaml_value(' spaced '));   // surrounding space
        $this->assertSame('""', format_yaml_value(''));                   // empty
    }

    public function testFormatYamlValueQuotesTypeCoercedTokens(): void
    {
        $this->assertSame('"true"', format_yaml_value('true'));
        $this->assertSame('"null"', format_yaml_value('null'));
        $this->assertSame('"42"', format_yaml_value('42'));
    }

    public function testFormatYamlValueEscapesInsideQuotes(): void
    {
        // A lone backslash is literal in a plain scalar, so it stays unquoted.
        $this->assertSame('a\\b', format_yaml_value('a\\b'));
        // When quoting is triggered, the quote and backslash are escaped.
        $this->assertSame('"a\\"b"', format_yaml_value('a"b'));
        $this->assertSame('"a\\\\b: c"', format_yaml_value('a\\b: c'));
    }

    public function testFormatYamlValueFormatsArrayAsFlowSequence(): void
    {
        $this->assertSame('[ News, PLC ]', format_yaml_value(['News', 'PLC']));
        // Elements that need quoting are quoted individually
        $this->assertSame('[ a, "b, c" ]', format_yaml_value(['a', 'b, c']));
        $this->assertSame('[]', format_yaml_value([]));
    }

    // --- format_datetime -----------------------------------------------------

    public function testFormatDatetimeConvertsGmtToTargetZone(): void
    {
        // 03:00 UTC becomes 12:00 +09:00 in Tokyo; UTC stays put with a +00:00 offset.
        $this->assertSame('2026-05-31 12:00:00 +09:00', format_datetime('2026-05-31T03:00:00', 'Asia/Tokyo'));
        $this->assertSame('2026-05-31 03:00:00 +00:00', format_datetime('2026-05-31T03:00:00', 'UTC'));
    }

    public function testFormatDatetimeReturnsEmptyForBlankOrUnparseable(): void
    {
        $this->assertSame('', format_datetime('', 'UTC'));
        $this->assertSame('', format_datetime('not a date', 'UTC'));
    }

    public function testFormatDatetimeFallsBackForInvalidTimezone(): void
    {
        // An invalid timezone falls back to the server default (set to UTC for this assertion).
        $prev = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $this->assertSame('2026-05-31 03:00:00 +00:00', format_datetime('2026-05-31T03:00:00', 'Not/AZone'));
        } finally {
            date_default_timezone_set($prev);
        }
    }

    // --- SiteResponse --------------------------------------------------------

    public function testSiteResponseHoldsValues(): void
    {
        $r = new SiteResponse(true, 200, 'hello', "X-Foo: bar");

        $this->assertTrue($r->success);
        $this->assertSame(200, $r->status);
        $this->assertSame('hello', $r->body);
        $this->assertSame('bar', $r->headers['x-foo']);
    }

    public function testSiteResponseDefaults(): void
    {
        $r = new SiteResponse(false);

        $this->assertFalse($r->success);
        $this->assertSame(0, $r->status);
        $this->assertFalse($r->body);
        $this->assertSame([], $r->headers);
    }
}
