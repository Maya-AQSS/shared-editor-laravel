<?php

declare(strict_types=1);

namespace Tests\Support;

use DOMDocument;
use DOMXPath;

/**
 * Semantic fingerprint of rendered editor HTML for the renderer parity oracle.
 * MUST stay logically identical to the JS version in
 * `shared-editor-react/src/parity/fingerprint.ts`.
 *
 * Captures content + structure (what must agree between the server-side
 * `TiptapHtmlRenderer` and the CSR static renderer), ignoring cosmetic markup.
 */
final class Fingerprint
{
    /**
     * @return array<string, mixed>
     */
    public static function of(string $html): array
    {
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // XML encoding hint keeps UTF-8 (á, í…) intact through loadHTML.
        $dom->loadHTML(
            '<?xml encoding="utf-8"?><div id="__root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $root = $xpath->query('//*[@id="__root"]')->item(0);

        $count = static fn (string $expr): int => $xpath->query($expr)->length;
        $attrs = static function (string $expr) use ($xpath): array {
            $out = [];
            foreach ($xpath->query($expr) as $node) {
                $out[] = $node->nodeValue ?? '';
            }
            sort($out);

            return $out;
        };

        $headings = [];
        foreach ($xpath->query('//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]') as $node) {
            $headings[] = (int) substr($node->nodeName, 1);
        }

        // Whitespace removed entirely: inter-element spacing (e.g. the space
        // emitted after a task checkbox, or block concatenation) is cosmetic and
        // must not break parity — only the sequence of visible characters matters.
        $text = $root !== null ? (string) $root->textContent : '';
        $text = (string) preg_replace('/\s+/u', '', $text);

        return [
            'text' => $text,
            'headings' => $headings,
            'links' => $attrs('//a[@href]/@href'),
            'images' => $attrs('//img[@src]/@src'),
            'strong' => $count('//strong'),
            'em' => $count('//em'),
            'u' => $count('//u'),
            's' => $count('//s'),
            'code' => $count('//code'),
            'ul' => $count('//ul'),
            'ol' => $count('//ol'),
            'li' => $count('//li'),
            'pre' => $count('//pre'),
            'blockquote' => $count('//blockquote'),
            'hr' => $count('//hr'),
            'table' => $count('//table'),
            'tr' => $count('//tr'),
            'th' => $count('//th'),
            'td' => $count('//td'),
            'aside' => $count('//aside'),
            'checkbox' => $count('//input[@type="checkbox"]'),
        ];
    }
}
