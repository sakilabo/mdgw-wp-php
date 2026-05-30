<?php

declare(strict_types=1);

use League\HTMLToMarkdown\HtmlConverter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../converter/LanguagePreConverter.php';
require_once __DIR__ . '/../converter/HtmlTableConverter.php';
require_once __DIR__ . '/../converter/BlockConverter.php';
require_once __DIR__ . '/../converter/FormConverter.php';

/**
 * HTML-to-Markdown conversion tests for the custom converters (under converter/).
 */
final class ConverterTest extends TestCase
{
    /**
     * Builds an HtmlConverter configured the same as page.php.
     * If you change the configuration, update both page.php and this method.
     * $formMode mirrors the config 'form_handling' value passed to FormConverter.
     */
    private function makeConverter(string $formMode = 'keep'): HtmlConverter
    {
        $converter = new HtmlConverter([
            'strip_tags'   => true,
            'remove_nodes' => 'script style',
            'hard_break'   => true,
            'header_style' => 'atx',
        ]);
        $converter->getEnvironment()->addConverter(new HtmlTableConverter());
        $converter->getEnvironment()->addConverter(new LanguagePreConverter());
        $converter->getEnvironment()->addConverter(new BlockConverter());
        $converter->getEnvironment()->addConverter(new FormConverter($formMode));

        return $converter;
    }

    public function testTableIsKeptAsHtmlNotPipeTable(): void
    {
        $md = $this->makeConverter()->convert(
            '<table><tbody><tr><td>a</td><td>b</td></tr></tbody></table>'
        );

        $this->assertStringContainsString('<table>', $md);
        $this->assertStringContainsString('<td>a</td>', $md);
        $this->assertStringContainsString('<td>b</td>', $md);
        // It must not have been converted into a pipe table
        $this->assertStringNotContainsString('|', $md);
    }

    public function testTableTagsAreSeparatedFromContentByBlankLines(): void
    {
        // A blank line right after <table> and right before </table> stops Markdown viewers
        // (e.g. Typora) from rendering a half-formed table while the block is still incomplete.
        $md = $this->makeConverter()->convert(
            '<table><tbody><tr><td>a</td><td>b</td></tr></tbody></table>'
        );

        $this->assertStringContainsString("<table>\n\n", $md);
        $this->assertStringContainsString("\n\n</table>", $md);
    }

    public function testPreWithClassBecomesLanguageFence(): void
    {
        $md = $this->makeConverter()->convert(
            '<pre class="mermaid">graph TD; A--&gt;B;</pre>'
        );

        $this->assertStringContainsString('```mermaid', $md);
    }

    public function testPreWithoutClassFallsBackToPlainFence(): void
    {
        $md = $this->makeConverter()->convert('<pre>plain text</pre>');

        $this->assertStringContainsString('```', $md);
        $this->assertStringNotContainsString('```mermaid', $md);
    }

    public function testBlockLevelTagsAreSeparatedFromNeighbours(): void
    {
        // The figure must not be joined onto one line with the surrounding blocks
        $md = $this->makeConverter()->convert(
            '<p>before</p><figure>fig</figure><p>after</p>'
        );

        $this->assertMatchesRegularExpression('/before\n\n+fig\n\n+after/', $md);
    }

    public function testDetailsSummaryAreSeparated(): void
    {
        // Guard against the regression where <summary>Heading</summary><p>Body</p> joins into "HeadingBody"
        $md = $this->makeConverter()->convert(
            '<details><summary>Heading</summary><p>Body</p></details>'
        );

        $this->assertMatchesRegularExpression('/Heading\n\n+Body/', $md);
    }

    public function testFormKeepModeKeepsHtmlBlockAndLabels(): void
    {
        // 'keep' (default): the form stays as a <form> ... </form> block and label text survives
        $md = $this->makeConverter()->convert(
            '<p>before</p><form><p>Name</p><input name="name"></form><p>after</p>'
        );

        $this->assertStringContainsString('<form>', $md);
        $this->assertStringContainsString('</form>', $md);
        $this->assertStringContainsString('Name', $md);
        // The form must not be glued onto the neighbouring paragraphs
        $this->assertMatchesRegularExpression('/before\n\n+<form>/', $md);
        $this->assertMatchesRegularExpression('/<\/form>\n\n+after/', $md);
    }

    public function testFormRemoveModeEmitsSelfClosingMarker(): void
    {
        // 'remove': the form is replaced by a self-closing <form /> marker and its contents are dropped
        $md = $this->makeConverter('remove')->convert(
            '<p>before</p><form><p>Name</p><input name="name"></form><p>after</p>'
        );

        $this->assertStringContainsString('<form />', $md);
        $this->assertStringNotContainsString('</form>', $md);
        // Inner content (the "Name" label) is discarded
        $this->assertStringNotContainsString('Name', $md);
        // The marker stays separated from the neighbouring paragraphs
        $this->assertMatchesRegularExpression('/before\n\n+<form \/>\n\n+after/', $md);
    }
}
