<?php

// Slim down an HTML *fragment* (e.g. WordPress content.rendered) for AI consumption. Three independent
// controls; the result then has each run of text whitespace collapsed to a single space, every line
// trimmed, and all blank lines dropped (the DOM round-trip keeps the source's inter-block whitespace,
// which would otherwise print as indentation and empty lines) -- except inside <pre>/<code>, whose
// whitespace (code indentation, blank lines) is content and is left untouched:
//   $keep_attrs  - attribute names kept on every element; all others are stripped (style/class/id/data-* ...)
//   $drop_tags   - tags removed node and all (e.g. script/style, whose contents are noise, not prose)
//   $unwrap_tags - tags removed but their children spliced into place (e.g. span: drop the wrapper, keep text)
function slim_html_fragment(string $html, array $keep_attrs, array $drop_tags = [], array $unwrap_tags = []): string
{
    if (trim($html) === '') {
        return $html;
    }
    // Strip document wrappers up front. The input is a fragment, so any <html>/<body> is spurious; removing
    // them as text (before parsing) also dodges libxml's data loss when a stray second <body> would be dropped.
    $html = preg_replace('~</?(?:html|body)\b[^>]*>~i', '', $html);

    $dom = new DOMDocument();
    // Prefix an XML encoding hint so libxml reads the bytes as UTF-8 (a fragment has no <head>/<meta>).
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR);

    // Remove drop tags entirely. Snapshot the live NodeList first, since removal mutates it mid-iteration.
    foreach ($drop_tags as $tag) {
        foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    // Strip every attribute except the kept names from all remaining elements.
    $keep = array_flip(array_map('strtolower', $keep_attrs));
    foreach ($dom->getElementsByTagName('*') as $el) {
        // Collect names first; removeAttribute() mutates the live attribute map mid-iteration otherwise.
        $names = [];
        foreach ($el->attributes as $attr) {
            $names[] = $attr->name;
        }
        foreach ($names as $name) {
            if (!isset($keep[strtolower($name)])) {
                $el->removeAttribute($name);
            }
        }
        if ($el->hasAttribute('href')) {
            // Ensure rel carries noreferrer, appended to any rel that survived the keep-filter
            // (without clobbering it, and without duplicating an existing noreferrer token).
            $rel = trim($el->getAttribute('rel'));
            $tokens = $rel === '' ? [] : preg_split('/\s+/', $rel);
            if (!in_array('noreferrer', $tokens, true)) {
                $tokens[] = 'noreferrer';
            }
            $el->setAttribute('rel', implode(' ', $tokens));
        }
    }

    // Unwrap: replace each tag with its children. Snapshot first; nested unwrap tags stay valid as their
    // node refs survive the move (children are spliced before the node, then the now-empty node is removed).
    foreach ($unwrap_tags as $tag) {
        foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
            $parent = $node->parentNode;
            if ($parent === null) {
                continue;
            }
            while ($node->firstChild !== null) {
                $parent->insertBefore($node->firstChild, $node);
            }
            $parent->removeChild($node);
        }
    }

    // Collapse each run of whitespace in text to a single space (HTML's own rule), skipping <pre>/<code>
    // descendants where whitespace is content. Done on the DOM so it never touches tags or attributes.
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('//text()[not(ancestor::pre) and not(ancestor::code)]') as $text) {
        // /u is intentional: it also folds full-width spaces (U+3000) into a single half-width space.
        $text->nodeValue = preg_replace('/\s+/u', ' ', $text->nodeValue);
    }

    // Output only the body's inner HTML; loadHTML wraps the fragment in <html><body>.
    $body = $dom->getElementsByTagName('body')->item(0);
    if ($body === null) {
        return $html;
    }
    $out = '';
    foreach ($body->childNodes as $child) {
        $out .= $dom->saveHTML($child);
    }

    // Shield <pre>/<code> blocks from line processing: swap each for a placeholder, so the trim/blank-line
    // pass leaves it intact, then restore them verbatim afterward. Matching <pre> first means a
    // <pre><code>...</code></pre> is captured whole, so its inner <code> is never reprocessed. The STX/ETX
    // (\x02..\x03) delimiters are deliberate -- they mark "start/end of text" and, unlike \0, survive trim().
    $shielded = [];
    $out = preg_replace_callback('~<(pre|code)\b[^>]*>.*?</\1\s*>~is', function ($m) use (&$shielded) {
        $shielded[] = $m[0];
        return "\x02" . (count($shielded) - 1) . "\x03";
    }, $out);

    // Trim each line and drop the blank ones (placeholders survive: \x02/\x03 and digits are not trimmed).
    $lines = [];
    foreach (explode("\n", $out) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    $out = implode("\n", $lines);

    // Restore the shielded blocks.
    return preg_replace_callback('~\x02(\d+)\x03~', fn($m) => $shielded[(int) $m[1]], $out);
}
