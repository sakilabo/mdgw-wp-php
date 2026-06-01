<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;

/**
 * Handles <form> so an AI reading the Markdown can tell where an interactive form was, with the
 * behaviour selected by the constructor mode:
 *  - 'keep'   (default): emit the form as a raw HTML block (<form> ... </form>) with its inner
 *             content converted to Markdown as usual. Field labels survive as readable text.
 *  - 'remove': drop the form's contents and emit just a self-closing <form /> marker.
 * Either way the marker is emitted at column 0 and wrapped in blank lines so CommonMark treats it
 * as an HTML block (and so it never glues onto an adjacent element).
 */
class FormConverter implements ConverterInterface
{
    private string $mode;

    public function __construct(string $mode = 'keep')
    {
        // Any value other than 'remove' falls back to 'keep'.
        $this->mode = $mode === 'remove' ? 'remove' : 'keep';
    }

    public function convert(ElementInterface $element): string
    {
        // 'remove': replace the whole form with a self-closing marker, discarding its contents.
        if ($this->mode === 'remove') {
            return "\n<form />\n\n";
        }
        // 'keep': emit the form tags as an HTML block; the inner content is already Markdown.
        $value = \trim($element->getValue());
        return "\n<form>\n\n" . $value . "\n\n</form>\n\n";
    }

    /**
     * @return string[]
     */
    public function getSupportedTags(): array
    {
        return ['form'];
    }
}
