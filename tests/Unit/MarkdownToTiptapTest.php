<?php

declare(strict_types=1);

use Maya\Editor\Converters\MarkdownToTiptap;

/** @param array<int,array<string,mixed>> $content */
function findNode(array $content, string $type): ?array
{
    foreach ($content as $node) {
        if (($node['type'] ?? null) === $type) {
            return $node;
        }
    }

    return null;
}

it('converts a heading to a heading node with level', function () {
    $content = MarkdownToTiptap::convert('## Programa del curso');
    $heading = findNode($content, 'heading');
    expect($heading)->not->toBeNull();
    expect($heading['attrs']['level'])->toBe(2);
    expect(json_encode($content))->not->toContain('## Programa');
});

it('converts inline bold to a bold mark', function () {
    $content = MarkdownToTiptap::convert('texto **negrita** fin');
    $json = json_encode($content);
    expect($json)->toContain('"bold"');
    expect($json)->not->toContain('**negrita**');
});

it('keeps intra-word underscores literal (CommonMark)', function () {
    $content = MarkdownToTiptap::convert('**NOMBRE_DEL_CICLO**');
    $json = json_encode($content);
    expect($json)->toContain('NOMBRE_DEL_CICLO');
    expect($json)->toContain('"bold"');
    expect($json)->not->toContain('"italic"');
});

it('converts an ordered list with bold items', function () {
    $content = MarkdownToTiptap::convert("1. **Introducción a Laravel**\n2. Rutas");
    $list = findNode($content, 'orderedList');
    expect($list)->not->toBeNull();
    expect($list['content'])->toHaveCount(2);
    expect($list['content'][0]['type'])->toBe('listItem');
    expect(json_encode($list))->toContain('"bold"');
});

it('converts a bullet list', function () {
    $content = MarkdownToTiptap::convert("- uno\n- dos");
    $list = findNode($content, 'bulletList');
    expect($list)->not->toBeNull();
    expect($list['content'])->toHaveCount(2);
});

it('converts a fenced code block keeping markdown literal inside', function () {
    $content = MarkdownToTiptap::convert("```\n## not a heading\n```");
    $code = findNode($content, 'codeBlock');
    expect($code)->not->toBeNull();
    expect($code['content'][0]['text'])->toContain('## not a heading');
});

it('converts links to link marks with href', function () {
    $content = MarkdownToTiptap::convert('ver [docs](https://x.y)');
    $json = json_encode($content, JSON_UNESCAPED_SLASHES);
    expect($json)->toContain('"link"');
    expect($json)->toContain('https://x.y');
});

it('converts blockquote and horizontal rule', function () {
    expect(findNode(MarkdownToTiptap::convert('> cita'), 'blockquote'))->not->toBeNull();
    expect(findNode(MarkdownToTiptap::convert("a\n\n---\n\nb"), 'horizontalRule'))->not->toBeNull();
});

it('converts a GFM pipe table', function () {
    $md = "| A | B |\n| - | - |\n| 1 | 2 |";
    $table = findNode(MarkdownToTiptap::convert($md), 'table');
    expect($table)->not->toBeNull();
    expect($table['content'][0]['type'])->toBe('tableRow');
});

it('returns an empty array for empty input', function () {
    expect(MarkdownToTiptap::convert(''))->toBe([]);
    expect(MarkdownToTiptap::convert('   '))->toBe([]);
});

it('wraps a doc via convertToDoc', function () {
    $doc = MarkdownToTiptap::convertToDoc('hola');
    expect($doc['type'])->toBe('doc');
    expect($doc['content'][0]['type'])->toBe('paragraph');
});
