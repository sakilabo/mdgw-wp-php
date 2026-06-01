<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;

/**
 * Outputs block-level tags the library has no converter for (figure, details, summary,
 * section, etc.) with a trailing blank line, so they are not glued onto the following
 * block on one line (e.g. <summary>Heading</summary><p>Body</p> -> "HeadingBody").
 */
class BlockConverter implements ConverterInterface
{
    public function convert(ElementInterface $element): string
    {
        $value = \trim($element->getValue());
        if ($value === '') {
            return ''; // emit nothing for empty contents
        }
        return $value . "\n\n"; // trailing blank line separates this block from the next
    }

    /**
     * @return string[]
     */
    public function getSupportedTags(): array
    {
        return [
            'figure',
            'figcaption',
            'details',
            'summary',
            'section',
            'article',
            'aside',
            'header',
            'footer',
            'main',
            'nav',
            'address',
            'dl',
            'dt',
            'dd',
        ];
    }
}
