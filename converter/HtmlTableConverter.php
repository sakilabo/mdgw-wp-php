<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use League\HTMLToMarkdown\Configuration;
use League\HTMLToMarkdown\ConfigurationAwareInterface;
use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;
use League\HTMLToMarkdown\PreConverterInterface;

/**
 * Keeps tables as raw HTML instead of pipe tables, which cannot represent cell line breaks,
 * colspan/rowspan, or headerless/vertical layouts. Each table-family tag is emitted one per
 * line at column 0 so CommonMark treats it as an HTML block; cell contents stay Markdown.
 */
class HtmlTableConverter implements ConverterInterface, PreConverterInterface, ConfigurationAwareInterface
{
    /** @var Configuration */
    protected $config;

    public function setConfig(Configuration $config): void
    {
        $this->config = $config;
    }

    public function preConvert(ElementInterface $element): void
    {
        // Drop whitespace between non-cell elements (table/tr, etc.); it would break the one-tag-per-line layout.
        $tag = $element->getTagName();
        if ($tag === 'th' || $tag === 'td' || $tag === 'caption') {
            return;
        }

        foreach ($element->getChildren() as $child) {
            if ($child->isText()) {
                $child->setFinalMarkdown('');
            }
        }
    }

    public function convert(ElementInterface $element): string
    {
        $tag   = $element->getTagName();
        $value = $element->getValue();

        switch ($tag) {
            case 'table':
                // Separate with blank lines before/after to make it a standalone HTML block.
                return "\n<table>\n\n" . $value . "\n</table>\n\n";

            case 'thead':
            case 'tbody':
            case 'tfoot':
            case 'tr':
                return '<' . $tag . ">\n" . $value . '</' . $tag . ">\n";

            case 'th':
            case 'td':
                return $this->convertCell($tag, $element, $value);

            case 'caption':
                return $this->wrapCell('caption', '', \trim($value));

            case 'colgroup':
            case 'col':
                // Column definitions are not needed for display, so drop them.
                return '';

            default:
                return '';
        }
    }

    private function convertCell(string $tag, ElementInterface $element, string $value): string
    {
        // colspan / rowspan are HTML, so keep them as-is ("1" is redundant, so omit it).
        $attrs = '';
        foreach (['colspan', 'rowspan'] as $name) {
            $v = \trim($element->getAttribute($name));
            if ($v !== '' && $v !== '1') {
                $attrs .= ' ' . $name . '="' . $v . '"';
            }
        }

        return $this->wrapCell($tag, $attrs, \trim($value));
    }

    // Output a cell (th/td/caption).
    private function wrapCell(string $tag, string $attrs, string $value): string
    {
        $open  = '<' . $tag . $attrs . '>';
        $close = '</' . $tag . '>';

        // Multi-line (block) contents: wrap in blank lines so they parse as Markdown; single line stays inline.
        if ($value !== '' && \strpos($value, "\n") !== false) {
            return $open . "\n\n" . $value . "\n\n" . $close . "\n";
        }

        return $open . $value . $close . "\n";
    }

    /**
     * @return string[]
     */
    public function getSupportedTags(): array
    {
        return ['table', 'tr', 'th', 'td', 'thead', 'tbody', 'tfoot', 'colgroup', 'col', 'caption'];
    }
}
