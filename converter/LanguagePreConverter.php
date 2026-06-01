<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use League\HTMLToMarkdown\Converter\PreformattedConverter;
use League\HTMLToMarkdown\ElementInterface;

/**
 * Reads the class on <pre class="..."> and emits a language-tagged fence (```lang),
 * e.g. <pre class="mermaid"> -> a block starting with ```mermaid. Falls back to the
 * standard PreformattedConverter's language-less fence when no language is found.
 */
class LanguagePreConverter extends PreformattedConverter
{
    public function convert(ElementInterface $element): string
    {
        $converted = parent::convert($element); // standard result, e.g. ```\n...\n```\n\n

        $language = $this->detectLanguage($element);
        if ($language === null) {
            return $converted;
        }

        // Swap in the language only for a language-less fence (```\n); a backtick-prefixed
        // result came from a <code> tag, so leave it untouched.
        if (\strncmp($converted, "```\n", 4) === 0) {
            return '```' . $language . \substr($converted, 3);
        }

        return $converted;
    }

    private function detectLanguage(ElementInterface $element): ?string
    {
        $class = \trim($element->getAttribute('class'));
        if ($class === '') {
            return null;
        }

        foreach (\preg_split('/\s+/', $class) as $name) {
            if (\preg_match('/^(?:language|lang)-(.+)$/', $name, $m)) {
                return $m[1]; // "language-xxx" / "lang-xxx" form
            }
        }

        // Otherwise treat the first class name as the language (e.g. mermaid)
        $first = \preg_split('/\s+/', $class)[0];

        return $first !== '' ? $first : null;
    }
}
