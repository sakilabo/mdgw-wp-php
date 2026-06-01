<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../slim_html_fragment.php';

/**
 * Tests for slim_html_fragment(). Inputs are HTML fragments (the real caller passes WordPress
 * content.rendered); behavior on non-HTML / malformed input is intentionally not specified.
 */
final class SlimHtmlFragmentTest extends TestCase
{
    /** The whitelist used in production (page.php). */
    private const KEEP = ['colspan', 'rowspan', 'href', 'src', 'alt'];
    private const DROP = ['script', 'style'];

    private function slim(string $html, array $unwrap = []): string
    {
        return slim_html_fragment($html, self::KEEP, self::DROP, $unwrap);
    }

    // --- attributes ----------------------------------------------------------

    public function testStripsNonWhitelistedAttributes(): void
    {
        $this->assertSame('<p>hi</p>', $this->slim('<p class="a" id="b" data-x="1" style="c">hi</p>'));
    }

    public function testKeepsColspanButDropsOtherCellAttributes(): void
    {
        $this->assertSame(
            '<table><tr><td colspan="2">c</td></tr></table>',
            $this->slim('<table><tr><td colspan="2" class="x">c</td></tr></table>')
        );
    }

    public function testKeepsHrefSrcAltAndDropsTheRest(): void
    {
        // An href-bearing element also gains rel="noreferrer" (the <img> has no href, so it does not).
        $this->assertSame(
            '<a href="u" rel="noreferrer">L</a><img src="s" alt="A">',
            $this->slim('<a href="u" target="_blank">L</a><img src="s" alt="A" width="9">')
        );
    }

    public function testNoreferrerIsAppendedToAKeptRelWithoutDuplicating(): void
    {
        // When rel survives the keep-filter, noreferrer is appended rather than clobbering it...
        $this->assertSame(
            '<a href="u" rel="nofollow noreferrer">L</a>',
            slim_html_fragment('<a href="u" rel="nofollow">L</a>', ['href', 'rel'])
        );
        // ...and an existing noreferrer token is not duplicated.
        $this->assertSame(
            '<a href="u" rel="noreferrer">L</a>',
            slim_html_fragment('<a href="u" rel="noreferrer">L</a>', ['href', 'rel'])
        );
    }

    // --- drop tags -----------------------------------------------------------

    public function testDropsScriptAndStyleWithTheirContents(): void
    {
        $this->assertSame('<p>a</p><p>b</p>', $this->slim('<p>a</p><script>X</script><style>Y</style><p>b</p>'));
    }

    // --- unwrap tags ---------------------------------------------------------

    public function testUnwrapsTagButKeepsChildrenIncludingNested(): void
    {
        $this->assertSame('<p>a b n c</p>', $this->slim('<p>a <span class="h">b <span>n</span></span> c</p>', ['span']));
    }

    // --- whitespace collapse -------------------------------------------------

    public function testCollapsesWhitespaceRunsIncludingNewlinesAndFullWidthSpace(): void
    {
        // Three regular spaces, a newline run, and two full-width spaces (U+3000) all become one space.
        $this->assertSame('<p>a b c d</p>', $this->slim("<p>a   b\n\nc\u{3000}\u{3000}d</p>"));
    }

    public function testPreservesUtf8Text(): void
    {
        $this->assertSame('<p>日本語 テスト</p>', $this->slim('<p>日本語  テスト</p>'));
    }

    // --- pre / code are shielded ---------------------------------------------

    public function testLeavesWhitespaceInsidePreAndCodeUntouched(): void
    {
        $in = "<div>\n\n  <p>a   b</p>\n"
            . "<pre><code>def f():\n    if x:\n\n        return 1\n</code></pre>\n"
            . "<p>後 <code>x   y</code></p>\n</div>";
        // Outside pre/code: whitespace collapsed, blank lines dropped, lines trimmed (all on one line).
        // Inside pre/code: indentation, the blank line, and the inline run "x   y" survive verbatim.
        // (The closing marker's indentation is stripped from every line by PHP's flexible heredoc.)
        $expected = <<<'HTML'
            <div> <p>a b</p> <pre><code>def f():
                if x:

                    return 1
            </code></pre> <p>後 <code>x   y</code></p> </div>
            HTML;
        $this->assertSame($expected, $this->slim($in));
    }

    // --- line trimming / blank lines -----------------------------------------

    public function testNoBlankOrIndentedLinesOutsidePre(): void
    {
        $out = $this->slim("<div>\n\n    <p>x</p>\n  \n</div>");
        $this->assertSame('<div> <p>x</p> </div>', $out);
    }

    // --- DOCTYPE / structural noise ------------------------------------------

    public function testRemovesDoctype(): void
    {
        $out = $this->slim("<!DOCTYPE html>\n<p>x</p>");
        $this->assertSame('<p>x</p>', $out);
        $this->assertStringNotContainsStringIgnoringCase('doctype', $out);
    }

    // --- document wrappers ---------------------------------------------------

    public function testStripsHtmlAndBodyWrappers(): void
    {
        $this->assertSame('<p>x</p>', $this->slim('<html lang="ja"><body class="c"><p>x</p></body></html>'));
    }

    public function testKeepsContentOfEveryBodyWhenWrappersAreStripped(): void
    {
        // libxml would drop a stray second <body>; pre-stripping the tags keeps both fragments' content.
        $this->assertSame('<p>a</p><p>b</p>', $this->slim('<body><p>a</p></body><body><p>b</p></body>'));
    }

    // --- empty input ---------------------------------------------------------

    public function testEmptyStringReturnedAsIs(): void
    {
        $this->assertSame('', $this->slim(''));
    }
}
