<?php

declare(strict_types=1);

namespace Maya\Editor\Renderers;

/**
 * Converts a BlockNote JSON document (legacy editor) to a ProseMirror/TipTap doc.
 *
 * Legacy shape (BlockNote):
 *   [{type:'paragraph', props:{}, content:[{type:'text', text:'x', styles:{bold:true}}], children:[]}, ...]
 *
 * Target shape (ProseMirror):
 *   {type:'doc', content:[{type:'paragraph', attrs:{}, content:[{type:'text', text:'x', marks:[{type:'bold'}]}]}, ...]}
 *
 * Lossy on purpose:
 *   - BlockNote's nested `children` on bulletListItem/numberedListItem is
 *     converted to flat ProseMirror `bulletList[listItem[paragraph]]`
 *     with nested lists.
 *   - Unknown block types fall through to a paragraph with the original
 *     content (mirrored by TiptapHtmlRenderer's `default` arm).
 */
final class BlockNoteToTiptap
{
    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    public static function convert(array $blocks): array
    {
        $content = [];
        $i = 0;
        $n = count($blocks);
        while ($i < $n) {
            $block = $blocks[$i];
            if (! is_array($block)) {
                $i++;
                continue;
            }
            $type = (string) ($block['type'] ?? 'paragraph');

            // Coalesce consecutive bulletListItem / numberedListItem / checkListItem
            // into a single list node (BlockNote stores them as siblings).
            if (in_array($type, ['bulletListItem', 'numberedListItem', 'checkListItem'], true)) {
                $listType = match ($type) {
                    'bulletListItem' => 'bulletList',
                    'numberedListItem' => 'orderedList',
                    'checkListItem' => 'taskList',
                };
                $items = [];
                while ($i < $n && is_array($blocks[$i]) && ($blocks[$i]['type'] ?? '') === $type) {
                    $items[] = self::convertListItem($blocks[$i], $type);
                    $i++;
                }
                $content[] = ['type' => $listType, 'content' => $items];
                continue;
            }

            $content[] = self::convertBlock($block);
            $i++;
        }

        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function convertBlock(array $block): array
    {
        $type = (string) ($block['type'] ?? 'paragraph');
        $props = (array) ($block['props'] ?? []);
        $inline = self::convertInline((array) ($block['content'] ?? []));
        $attrs = self::propsToAttrs($props);

        return match ($type) {
            'heading' => [
                'type' => 'heading',
                'attrs' => array_merge($attrs, ['level' => max(1, min(6, (int) ($props['level'] ?? 2)))]),
                'content' => $inline,
            ],
            'paragraph' => [
                'type' => 'paragraph',
                'attrs' => $attrs,
                'content' => $inline,
            ],
            'quote' => [
                'type' => 'blockquote',
                'attrs' => $attrs,
                'content' => [['type' => 'paragraph', 'content' => $inline]],
            ],
            'codeBlock' => [
                'type' => 'codeBlock',
                'attrs' => $attrs,
                'content' => $inline,
            ],
            'image' => [
                'type' => 'image',
                'attrs' => [
                    'src' => (string) ($props['url'] ?? ''),
                    'alt' => (string) ($props['caption'] ?? ''),
                    'caption' => (string) ($props['caption'] ?? ''),
                ],
            ],
            'table' => self::convertTable((array) ($block['content'] ?? [])),
            default => [
                'type' => 'paragraph',
                'attrs' => array_merge($attrs, ['data-original-type' => $type]),
                'content' => $inline,
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function convertListItem(array $block, string $blockType): array
    {
        $props = (array) ($block['props'] ?? []);
        $inline = self::convertInline((array) ($block['content'] ?? []));
        $attrs = self::propsToAttrs($props);

        $itemContent = [['type' => 'paragraph', 'content' => $inline]];

        // Nested children → recurse into convert() and flatten as nested list inside the item.
        $children = (array) ($block['children'] ?? []);
        if ($children !== []) {
            $childDoc = self::convert($children);
            foreach ((array) ($childDoc['content'] ?? []) as $childNode) {
                if (is_array($childNode)) {
                    $itemContent[] = $childNode;
                }
            }
        }

        if ($blockType === 'checkListItem') {
            return [
                'type' => 'taskItem',
                'attrs' => array_merge($attrs, ['checked' => ! empty($props['checked'])]),
                'content' => $itemContent,
            ];
        }

        return [
            'type' => 'listItem',
            'attrs' => $attrs,
            'content' => $itemContent,
        ];
    }

    /**
     * BlockNote table shape:
     *   { rows: [ { cells: [ [span...], ... ] }, ... ] }
     *
     * ProseMirror table shape:
     *   { type: 'table', content: [ {type:'tableRow', content:[{type:'tableCell', content:[...]}, ...]} ] }
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private static function convertTable(array $content): array
    {
        $rows = (array) ($content['rows'] ?? []);
        $proseRows = [];
        $isFirstRow = true;
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['cells']) || ! is_array($row['cells'])) {
                continue;
            }
            $cells = [];
            foreach ($row['cells'] as $cell) {
                $cellContent = [];
                $cellAttrs = [];
                if (is_array($cell)) {
                    if (isset($cell['content']) && is_array($cell['content'])) {
                        // New BlockNote shape: cell is {content, props}
                        $cellContent = self::convertInline($cell['content']);
                        if (isset($cell['props']) && is_array($cell['props'])) {
                            if (isset($cell['props']['colspan'])) {
                                $cellAttrs['colspan'] = (int) $cell['props']['colspan'];
                            }
                            if (isset($cell['props']['rowspan'])) {
                                $cellAttrs['rowspan'] = (int) $cell['props']['rowspan'];
                            }
                        }
                    } else {
                        // Old BlockNote shape: cell is array of inline spans directly
                        $cellContent = self::convertInline($cell);
                    }
                }
                $cells[] = [
                    'type' => $isFirstRow ? 'tableHeader' : 'tableCell',
                    'attrs' => $cellAttrs,
                    'content' => [['type' => 'paragraph', 'content' => $cellContent]],
                ];
            }
            $proseRows[] = ['type' => 'tableRow', 'content' => $cells];
            $isFirstRow = false;
        }

        return ['type' => 'table', 'content' => $proseRows];
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     * @return array<int, array<string, mixed>>
     */
    private static function convertInline(array $content): array
    {
        $out = [];
        foreach ($content as $span) {
            if (! is_array($span)) {
                continue;
            }
            $type = (string) ($span['type'] ?? 'text');
            if ($type === 'text') {
                $styles = (array) ($span['styles'] ?? []);
                $marks = self::stylesToMarks($styles);
                $text = (string) ($span['text'] ?? '');
                if ($text === '') {
                    continue;
                }
                $node = ['type' => 'text', 'text' => $text];
                if ($marks !== []) {
                    $node['marks'] = $marks;
                }
                $out[] = $node;
            } elseif ($type === 'link') {
                $href = (string) ($span['href'] ?? '');
                $linkMark = ['type' => 'link', 'attrs' => ['href' => $href]];
                foreach ((array) ($span['content'] ?? []) as $inner) {
                    if (! is_array($inner) || ($inner['type'] ?? '') !== 'text') {
                        continue;
                    }
                    $styles = (array) ($inner['styles'] ?? []);
                    $marks = self::stylesToMarks($styles);
                    $marks[] = $linkMark;
                    $text = (string) ($inner['text'] ?? '');
                    if ($text === '') {
                        continue;
                    }
                    $out[] = ['type' => 'text', 'text' => $text, 'marks' => $marks];
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $styles
     * @return array<int, array<string, mixed>>
     */
    private static function stylesToMarks(array $styles): array
    {
        $marks = [];
        if (! empty($styles['bold'])) {
            $marks[] = ['type' => 'bold'];
        }
        if (! empty($styles['italic'])) {
            $marks[] = ['type' => 'italic'];
        }
        if (! empty($styles['underline'])) {
            $marks[] = ['type' => 'underline'];
        }
        if (! empty($styles['strike'])) {
            $marks[] = ['type' => 'strike'];
        }
        if (! empty($styles['code'])) {
            $marks[] = ['type' => 'code'];
        }
        if (! empty($styles['textColor']) && $styles['textColor'] !== 'default') {
            $marks[] = ['type' => 'textStyle', 'attrs' => ['color' => (string) $styles['textColor']]];
        }
        if (! empty($styles['backgroundColor']) && $styles['backgroundColor'] !== 'default') {
            $marks[] = ['type' => 'highlight', 'attrs' => ['color' => (string) $styles['backgroundColor']]];
        }

        return $marks;
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private static function propsToAttrs(array $props): array
    {
        $attrs = [];
        if (! empty($props['textColor']) && $props['textColor'] !== 'default') {
            $attrs['textColor'] = (string) $props['textColor'];
        }
        if (! empty($props['backgroundColor']) && $props['backgroundColor'] !== 'default') {
            $attrs['backgroundColor'] = (string) $props['backgroundColor'];
        }
        if (! empty($props['textAlignment'])) {
            $attrs['textAlign'] = (string) $props['textAlignment'];
        }

        return $attrs;
    }
}
