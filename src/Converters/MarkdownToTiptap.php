<?php

declare(strict_types=1);

namespace Maya\Editor\Converters;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\Strikethrough\Strikethrough;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Extension\TaskList\TaskListItemMarker;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;

/**
 * Converts a Markdown string into a TipTap/ProseMirror content array by walking
 * the CommonMark (GFM) AST.
 *
 * This is the round-trip companion to {@see \Maya\Editor\Renderers\TiptapHtmlRenderer}
 * and mirrors the JS `markdownToHtml` + `htmlToTiptapDoc` ingestion path. It
 * exists so Markdown is parsed into REAL nodes at write time (seeds, data
 * repair) instead of being stored as a literal text node — which is what made
 * previews show "## " / "**bold**" verbatim.
 *
 * Using the CommonMark AST (rather than regex) gets edge cases right, e.g.
 * intra-word underscores in `NOMBRE_DEL_CICLO` stay literal.
 */
final class MarkdownToTiptap
{
    /**
     * @return list<array<string, mixed>> TipTap content array (block nodes)
     */
    public static function convert(string $markdown): array
    {
        if (trim($markdown) === '') {
            return [];
        }

        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        $document = (new MarkdownParser($environment))->parse($markdown);

        return self::blocks($document);
    }

    /**
     * @return array{type: string, content: list<array<string, mixed>>}
     */
    public static function convertToDoc(string $markdown): array
    {
        return ['type' => 'doc', 'content' => self::convert($markdown)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function blocks(Node $container): array
    {
        $out = [];
        foreach ($container->children() as $child) {
            $node = self::block($child);
            if ($node !== null) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function block(Node $node): ?array
    {
        return match (true) {
            $node instanceof Heading => [
                'type' => 'heading',
                'attrs' => ['level' => $node->getLevel()],
                'content' => self::inlines($node),
            ],
            $node instanceof Paragraph => ['type' => 'paragraph', 'content' => self::inlines($node)],
            $node instanceof ListBlock => self::list($node),
            $node instanceof BlockQuote => ['type' => 'blockquote', 'content' => self::blocks($node)],
            $node instanceof FencedCode => self::code($node->getLiteral()),
            $node instanceof IndentedCode => self::code($node->getLiteral()),
            $node instanceof ThematicBreak => ['type' => 'horizontalRule'],
            $node instanceof Table => self::table($node),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function code(string $literal): array
    {
        $literal = rtrim($literal, "\n");

        return [
            'type' => 'codeBlock',
            'content' => $literal === '' ? [] : [['type' => 'text', 'text' => $literal]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function list(ListBlock $node): array
    {
        $data = $node->getListData();
        $ordered = $data->type === ListBlock::TYPE_ORDERED;

        $items = [];
        $isTaskList = false;
        foreach ($node->children() as $li) {
            [$item, $isTask] = self::listItem($li);
            $isTaskList = $isTaskList || $isTask;
            $items[] = $item;
        }

        if ($isTaskList) {
            return ['type' => 'taskList', 'content' => $items];
        }

        $list = [
            'type' => $ordered ? 'orderedList' : 'bulletList',
            'content' => $items,
        ];
        if ($ordered && $data->start !== null && $data->start !== 1) {
            $list['attrs'] = ['start' => $data->start];
        }

        return $list;
    }

    /**
     * @return array{0: array<string, mixed>, 1: bool} [node, isTaskItem]
     */
    private static function listItem(Node $li): array
    {
        $checked = null;
        foreach ($li->children() as $blk) {
            if (! $blk instanceof Paragraph) {
                continue;
            }
            foreach ($blk->children() as $inl) {
                if ($inl instanceof TaskListItemMarker) {
                    $checked = $inl->isChecked();
                    break 2;
                }
            }
        }

        $content = self::blocks($li);

        if ($checked !== null) {
            return [['type' => 'taskItem', 'attrs' => ['checked' => $checked], 'content' => $content], true];
        }

        return [['type' => 'listItem', 'content' => $content], false];
    }

    /**
     * @return array<string, mixed>
     */
    private static function table(Table $node): array
    {
        $rows = [];
        foreach ($node->children() as $section) {
            if (! $section instanceof TableSection) {
                continue;
            }
            foreach ($section->children() as $row) {
                if (! $row instanceof TableRow) {
                    continue;
                }
                $cells = [];
                foreach ($row->children() as $cell) {
                    if (! $cell instanceof TableCell) {
                        continue;
                    }
                    $tag = $cell->getType() === TableCell::TYPE_HEADER ? 'tableHeader' : 'tableCell';
                    $cells[] = [
                        'type' => $tag,
                        'content' => [['type' => 'paragraph', 'content' => self::inlines($cell)]],
                    ];
                }
                $rows[] = ['type' => 'tableRow', 'content' => $cells];
            }
        }

        return ['type' => 'table', 'content' => $rows];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function inlines(Node $container): array
    {
        $out = [];
        foreach ($container->children() as $child) {
            self::inline($child, [], $out);
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $marks
     * @param  list<array<string, mixed>>  $out
     */
    private static function inline(Node $node, array $marks, array &$out): void
    {
        if ($node instanceof Text) {
            $out[] = self::text($node->getLiteral(), $marks);

            return;
        }
        if ($node instanceof Code) {
            $out[] = self::text($node->getLiteral(), [...$marks, ['type' => 'code']]);

            return;
        }
        if ($node instanceof Strong) {
            self::inlineChildren($node, [...$marks, ['type' => 'bold']], $out);

            return;
        }
        if ($node instanceof Emphasis) {
            self::inlineChildren($node, [...$marks, ['type' => 'italic']], $out);

            return;
        }
        if ($node instanceof Strikethrough) {
            self::inlineChildren($node, [...$marks, ['type' => 'strike']], $out);

            return;
        }
        if ($node instanceof Link) {
            self::inlineChildren($node, [...$marks, ['type' => 'link', 'attrs' => ['href' => $node->getUrl()]]], $out);

            return;
        }
        if ($node instanceof Image) {
            $out[] = ['type' => 'image', 'attrs' => ['src' => $node->getUrl(), 'alt' => self::plainText($node)]];

            return;
        }
        if ($node instanceof Newline) {
            $out[] = $node->getType() === Newline::HARDBREAK
                ? ['type' => 'hardBreak']
                : self::text(' ', $marks);

            return;
        }

        // TaskListItemMarker, HtmlInline and any other inline: descend (markers
        // have no children, so they are effectively dropped).
        self::inlineChildren($node, $marks, $out);
    }

    /**
     * @param  list<array<string, mixed>>  $marks
     * @param  list<array<string, mixed>>  $out
     */
    private static function inlineChildren(Node $node, array $marks, array &$out): void
    {
        foreach ($node->children() as $child) {
            self::inline($child, $marks, $out);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $marks
     * @return array<string, mixed>
     */
    private static function text(string $text, array $marks): array
    {
        $node = ['type' => 'text', 'text' => $text];
        if ($marks !== []) {
            $node['marks'] = array_values($marks);
        }

        return $node;
    }

    private static function plainText(Node $node): string
    {
        $text = '';
        foreach ($node->children() as $child) {
            if ($child instanceof Text || $child instanceof Code) {
                $text .= $child->getLiteral();
            } else {
                $text .= self::plainText($child);
            }
        }

        return $text;
    }
}
