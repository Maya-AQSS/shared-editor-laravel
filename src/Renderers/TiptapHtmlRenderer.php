<?php

declare(strict_types=1);

namespace Maya\Editor\Renderers;

/**
 * Converts a ProseMirror/TipTap JSON document to safe HTML.
 *
 * ProseMirror shape:
 *   {
 *     type: 'doc',
 *     content: [
 *       { type: 'paragraph', attrs: {...}, content: [...inline] },
 *       ...
 *     ]
 *   }
 *
 * Inline nodes are `{ type: 'text', text: '...', marks: [{type:'bold'}, ...] }`.
 *
 * Output is the semantic HTML that WeasyPrint can transform into PDF/UA.
 * This renderer keeps strict parity with the legacy `BlockNoteHtmlRenderer`
 * shape (validated by oracle tests at the consumer side).
 */
final class TiptapHtmlRenderer
{
    /**
     * Render a full ProseMirror doc node.
     *
     * @param  array<string, mixed>  $doc
     */
    public static function renderDoc(array $doc): string
    {
        if (($doc['type'] ?? null) !== 'doc') {
            return '';
        }

        return self::renderNodes((array) ($doc['content'] ?? []));
    }

    /**
     * Render an array of block-level nodes.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     */
    public static function renderNodes(array $nodes): string
    {
        $buffer = '';
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $buffer .= self::renderNode($node);
        }

        return $buffer;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function renderNode(array $node): string
    {
        $type = (string) ($node['type'] ?? 'paragraph');
        $attrs = (array) ($node['attrs'] ?? []);
        $content = (array) ($node['content'] ?? []);
        $inline = self::renderInline($content);

        $style = self::attrsToStyle($attrs);
        $styleAttr = $style !== '' ? ' style="'.self::escape($style).'"' : '';

        return match ($type) {
            'heading' => self::renderHeading($attrs, $inline, $styleAttr),
            'paragraph' => '<p'.$styleAttr.'>'.$inline.'</p>',
            'bulletList' => '<ul>'.self::renderNodes($content).'</ul>',
            'orderedList' => '<ol>'.self::renderNodes($content).'</ol>',
            'listItem' => '<li'.$styleAttr.'>'.self::renderListItemContent($content).'</li>',
            'taskList' => '<ul class="checklist">'.self::renderNodes($content).'</ul>',
            'taskItem' => self::renderTaskItem($attrs, $content, $styleAttr),
            'blockquote' => '<blockquote'.$styleAttr.'>'.self::renderNodes($content).'</blockquote>',
            'codeBlock' => '<pre><code>'.self::escapeText($content).'</code></pre>',
            'table' => self::renderTable($content),
            'image' => self::renderImage($attrs),
            'horizontalRule' => '<hr>',
            'hardBreak' => '<br>',
            'iframe' => self::renderIframe($attrs),
            'alert' => self::renderAlert($attrs, $content),
            default => '<div data-node-type="'.self::escape($type).'"'.$styleAttr.'>'.$inline.'</div>',
        };
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private static function renderHeading(array $attrs, string $inline, string $styleAttr): string
    {
        $level = (int) ($attrs['level'] ?? 2);
        $level = max(1, min(6, $level));

        return '<h'.$level.$styleAttr.'>'.$inline.'</h'.$level.'>';
    }

    /**
     * listItem content is typically [paragraph(...), nestedList?].
     *
     * @param  array<int, array<string, mixed>>  $content
     */
    private static function renderListItemContent(array $content): string
    {
        $out = '';
        foreach ($content as $child) {
            if (! is_array($child)) {
                continue;
            }
            $type = (string) ($child['type'] ?? '');
            if ($type === 'paragraph') {
                $out .= self::renderInline((array) ($child['content'] ?? []));
            } else {
                $out .= self::renderNode($child);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<int, array<string, mixed>>  $content
     */
    private static function renderTaskItem(array $attrs, array $content, string $styleAttr): string
    {
        $checked = ! empty($attrs['checked']) ? ' checked' : '';
        $inner = self::renderListItemContent($content);

        return '<li'.$styleAttr.'><input type="checkbox" disabled'.$checked.'> '.$inner.'</li>';
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private static function renderTable(array $rows): string
    {
        $rowNodes = [];
        foreach ($rows as $row) {
            if (is_array($row) && ($row['type'] ?? null) === 'tableRow') {
                $rowNodes[] = $row;
            }
        }
        if ($rowNodes === []) {
            return '';
        }

        $html = '<table><tbody>';
        $isHeaderRow = true;

        foreach ($rowNodes as $row) {
            $html .= '<tr>';
            $cells = (array) ($row['content'] ?? []);
            foreach ($cells as $cell) {
                if (! is_array($cell)) {
                    continue;
                }
                $cellType = (string) ($cell['type'] ?? '');
                $cellAttrs = (array) ($cell['attrs'] ?? []);
                $colspan = (int) ($cellAttrs['colspan'] ?? 1);
                $rowspan = (int) ($cellAttrs['rowspan'] ?? 1);
                $colAttr = $colspan > 1 ? ' colspan="'.$colspan.'"' : '';
                $rowAttr = $rowspan > 1 ? ' rowspan="'.$rowspan.'"' : '';

                $cellHtml = self::renderNodes((array) ($cell['content'] ?? []));

                $tag = ($cellType === 'tableHeader' || $isHeaderRow) ? 'th' : 'td';
                $html .= '<'.$tag.$colAttr.$rowAttr.'>'.$cellHtml.'</'.$tag.'>';
            }
            $html .= '</tr>';
            $isHeaderRow = false;
        }

        return $html.'</tbody></table>';
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private static function renderImage(array $attrs): string
    {
        $src = (string) ($attrs['src'] ?? '');
        $alt = (string) ($attrs['alt'] ?? '');
        $caption = (string) ($attrs['caption'] ?? '');
        if ($src === '') {
            return '';
        }

        $img = '<img src="'.self::escape($src).'" alt="'.self::escape($alt).'">';

        return $caption !== ''
            ? '<figure>'.$img.'<figcaption>'.self::escape($caption).'</figcaption></figure>'
            : $img;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private static function renderIframe(array $attrs): string
    {
        $src = (string) ($attrs['src'] ?? '');
        if ($src === '') {
            return '';
        }
        $title = (string) ($attrs['title'] ?? '');
        $titleAttr = $title !== '' ? ' title="'.self::escape($title).'"' : '';

        return '<iframe src="'.self::escape($src).'"'.$titleAttr.' sandbox="allow-scripts allow-same-origin" loading="lazy"></iframe>';
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<int, array<string, mixed>>  $content
     */
    private static function renderAlert(array $attrs, array $content): string
    {
        $variant = (string) ($attrs['variant'] ?? 'info');
        $allowed = ['info', 'warning', 'success', 'danger'];
        if (! in_array($variant, $allowed, true)) {
            $variant = 'info';
        }

        return '<aside class="alert alert-'.$variant.'" role="note">'.self::renderNodes($content).'</aside>';
    }

    /**
     * Render inline content (text + marks).
     *
     * @param  array<int, array<string, mixed>>  $content
     */
    private static function renderInline(array $content): string
    {
        $out = '';
        foreach ($content as $node) {
            if (! is_array($node)) {
                continue;
            }
            $type = (string) ($node['type'] ?? 'text');
            if ($type === 'text') {
                $text = self::escape((string) ($node['text'] ?? ''));
                $marks = (array) ($node['marks'] ?? []);
                $out .= self::wrapMarks($text, $marks);
            } elseif ($type === 'hardBreak') {
                $out .= '<br>';
            }
        }

        return $out;
    }

    /**
     * Apply marks in a deterministic order (matches BlockNote legacy):
     *   bold → italic → underline → strike → code → link → highlight
     *
     * @param  array<int, array<string, mixed>>  $marks
     */
    private static function wrapMarks(string $text, array $marks): string
    {
        $hasBold = false;
        $hasItalic = false;
        $hasUnderline = false;
        $hasStrike = false;
        $hasCode = false;
        $link = null;
        $textColor = null;
        $bgColor = null;

        foreach ($marks as $mark) {
            if (! is_array($mark)) {
                continue;
            }
            $markType = (string) ($mark['type'] ?? '');
            $markAttrs = (array) ($mark['attrs'] ?? []);
            match ($markType) {
                'bold' => $hasBold = true,
                'italic' => $hasItalic = true,
                'underline' => $hasUnderline = true,
                'strike' => $hasStrike = true,
                'code' => $hasCode = true,
                'link' => $link = (string) ($markAttrs['href'] ?? ''),
                'textStyle' => $textColor = (string) ($markAttrs['color'] ?? ''),
                'highlight' => $bgColor = (string) ($markAttrs['color'] ?? ''),
                default => null,
            };
        }

        if ($textColor !== null && $textColor !== '') {
            $text = '<span style="color:'.self::sanitizeColor($textColor).'">'.$text.'</span>';
        }
        if ($bgColor !== null && $bgColor !== '') {
            $text = '<span style="background-color:'.self::sanitizeColor($bgColor).'">'.$text.'</span>';
        }
        if ($hasBold) {
            $text = '<strong>'.$text.'</strong>';
        }
        if ($hasItalic) {
            $text = '<em>'.$text.'</em>';
        }
        if ($hasUnderline) {
            $text = '<u>'.$text.'</u>';
        }
        if ($hasStrike) {
            $text = '<s>'.$text.'</s>';
        }
        if ($hasCode) {
            $text = '<code>'.$text.'</code>';
        }
        if ($link !== null) {
            $validatedLink = self::validateUrlScheme($link);
            $text = '<a href="'.self::escape($validatedLink).'">'.$text.'</a>';
        }

        return $text;
    }

    /**
     * Map node attrs (textColor, backgroundColor, textAlign) to inline CSS.
     *
     * @param  array<string, mixed>  $attrs
     */
    private static function attrsToStyle(array $attrs): string
    {
        $parts = [];
        if (! empty($attrs['textColor']) && $attrs['textColor'] !== 'default') {
            $parts[] = 'color:'.self::sanitizeColor((string) $attrs['textColor']);
        }
        if (! empty($attrs['backgroundColor']) && $attrs['backgroundColor'] !== 'default') {
            $parts[] = 'background-color:'.self::sanitizeColor((string) $attrs['backgroundColor']);
        }
        if (! empty($attrs['textAlign'])) {
            $align = (string) $attrs['textAlign'];
            if (in_array($align, ['left', 'center', 'right', 'justify'], true)) {
                $parts[] = 'text-align:'.$align;
            }
        }

        return implode(';', $parts);
    }

    /**
     * Validate link href against allowed URI schemes.
     * Prevents javascript:, data:, vbscript: and other dangerous protocols.
     *
     * Allowed schemes: http, https, mailto, tel, fragment (#), relative paths (/, ./, ../)
     */
    private static function validateUrlScheme(string $href): string
    {
        if ($href === '') {
            return '';
        }

        // Pattern matches: https:, http:, mailto:, tel:, #, /, ./, ../
        $pattern = '/^(https?:|mailto:|tel:|#|\/|\.\/|\.\.\/)/i';
        if (preg_match($pattern, $href) === 1) {
            return $href;
        }

        // If href doesn't match allowed schemes, return empty string (link becomes useless).
        return '';
    }

    /**
     * Whitelist colors: hex / named / "default" → inherit.
     */
    private static function sanitizeColor(string $value): string
    {
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) === 1) {
            return $value;
        }
        $named = ['red', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink', 'gray', 'black', 'white'];
        if (in_array(strtolower($value), $named, true)) {
            return strtolower($value);
        }

        return 'inherit';
    }

    /**
     * Extract raw text from a codeBlock content array.
     *
     * @param  array<int, array<string, mixed>>  $content
     */
    private static function escapeText(array $content): string
    {
        $text = '';
        foreach ($content as $node) {
            if (is_array($node) && ($node['type'] ?? '') === 'text') {
                $text .= (string) ($node['text'] ?? '');
            }
        }

        return self::escape($text);
    }

    /**
     * Framework-agnostic HTML escape (does not rely on Laravel's `e()`).
     */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
