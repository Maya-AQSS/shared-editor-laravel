<?php

declare(strict_types=1);

namespace Tests\Unit;

use Maya\Editor\Renderers\TiptapHtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * TiptapHtmlRenderer tests: validates that the renderer produces safe HTML
 * from Tiptap/ProseMirror JSON documents, with emphasis on XSS prevention,
 * protocol blocking (javascript:, data:, vbscript:), color sanitization,
 * numeric cast for colspan/rowspan, and mark/list/table rendering.
 */
final class TiptapHtmlRendererTest extends TestCase
{
    private static function render(array $doc): string
    {
        return TiptapHtmlRenderer::renderDoc($doc);
    }

    // ─── test_01: empty doc ─────────────────────────────────────────────────

    public function test_01_renders_empty_array_to_empty_string(): void
    {
        $doc = ['type' => 'doc', 'content' => []];
        $this->assertSame('', self::render($doc));
    }

    // ─── test_02: paragraph ─────────────────────────────────────────────────

    public function test_02_renders_paragraph_with_inline_text(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'attrs' => [],
                'content' => [['type' => 'text', 'text' => 'Hola mundo']],
            ]],
        ];

        $html = self::render($doc);
        $this->assertStringContainsString('<p>Hola mundo</p>', $html);
    }

    // ─── test_03: heading levels 1–6 ────────────────────────────────────────

    public function test_03_renders_heading_levels_1_to_6(): void
    {
        $docs = [
            1 => ['type' => 'doc', 'content' => [[
                'type' => 'heading',
                'attrs' => ['level' => 1],
                'content' => [['type' => 'text', 'text' => 'T1']],
            ]]],
            2 => ['type' => 'doc', 'content' => [[
                'type' => 'heading',
                'attrs' => ['level' => 2],
                'content' => [['type' => 'text', 'text' => 'T2']],
            ]]],
            3 => ['type' => 'doc', 'content' => [[
                'type' => 'heading',
                'attrs' => ['level' => 3],
                'content' => [['type' => 'text', 'text' => 'T3']],
            ]]],
            4 => ['type' => 'doc', 'content' => [[
                'type' => 'heading',
                'attrs' => ['level' => 4],
                'content' => [['type' => 'text', 'text' => 'T4']],
            ]]],
            5 => ['type' => 'doc', 'content' => [[
                'type' => 'heading',
                'attrs' => ['level' => 5],
                'content' => [['type' => 'text', 'text' => 'T5']],
            ]]],
            6 => ['type' => 'doc', 'content' => [[
                'type' => 'heading',
                'attrs' => ['level' => 6],
                'content' => [['type' => 'text', 'text' => 'T6']],
            ]]],
        ];

        foreach ($docs as $level => $doc) {
            $html = self::render($doc);
            $this->assertStringContainsString('<h'.$level.'>T'.$level.'</h'.$level.'>', $html);
        }
    }

    // ─── test_04: invalid heading level clamped ────────────────────────────

    public function test_04_clamps_invalid_heading_level(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'heading',
                'attrs' => ['level' => 99],
                'content' => [['type' => 'text', 'text' => 'X']],
            ]],
        ];

        $html = self::render($doc);
        $this->assertStringContainsString('<h6>X</h6>', $html);
    }

    // ─── test_05: HTML escaping in text ────────────────────────────────────

    public function test_05_escapes_html_in_user_text(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'attrs' => [],
                'content' => [['type' => 'text', 'text' => '<script>alert("xss")</script>']],
            ]],
        ];

        $html = self::render($doc);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ─── test_06: mark nesting (bold, italic, underline) ────────────────────

    public function test_06_applies_bold_italic_underline_marks(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'attrs' => [],
                'content' => [[
                    'type' => 'text',
                    'text' => 'foo',
                    'marks' => [
                        ['type' => 'bold'],
                        ['type' => 'italic'],
                        ['type' => 'underline'],
                    ],
                ]],
            ]],
        ];

        $html = self::render($doc);
        // Mark order in TiptapHtmlRenderer: bold → italic → underline.
        $this->assertStringContainsString('<u><em><strong>foo</strong></em></u>', $html);
    }

    // ─── test_07: bullet list ───────────────────────────────────────────────

    public function test_07_renders_bullet_list_item(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'bulletList',
                'content' => [[
                    'type' => 'listItem',
                    'attrs' => [],
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => [['type' => 'text', 'text' => 'item1']],
                    ]],
                ]],
            ]],
        ];

        $html = self::render($doc);
        $this->assertStringContainsString('<ul><li>item1</li></ul>', $html);
    }

    // ─── test_08: link (safe URL) ───────────────────────────────────────────

    public function test_08_renders_link(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'attrs' => [],
                'content' => [[
                    'type' => 'text',
                    'text' => 'click',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]],
                ]],
            ]],
        ];

        $html = self::render($doc);
        $this->assertStringContainsString('<a href="https://example.com">click</a>', $html);
    }

    // ─── test_09: javascript: protocol blocked ──────────────────────────────

    public function test_09_blocks_javascript_protocol_in_link(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'attrs' => [],
                'content' => [[
                    'type' => 'text',
                    'text' => 'x',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]],
                ]],
            ]],
        ];

        $html = self::render($doc);
        $this->assertStringContainsString('href=""', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    // ─── test_10: expression() in color sanitized ───────────────────────────

    public function test_10_sanitizes_color_to_hex_or_named_only(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'attrs' => ['textColor' => 'expression(alert(1))'],
                'content' => [['type' => 'text', 'text' => 'x']],
            ]],
        ];

        $html = self::render($doc);
        $this->assertStringNotContainsString('expression', $html);
        $this->assertStringContainsString('color:inherit', $html);
    }

    // ─── test_11: valid hex color ───────────────────────────────────────────

    public function test_11_accepts_valid_hex_color(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'attrs' => ['textColor' => '#abcdef'],
                'content' => [['type' => 'text', 'text' => 'x']],
            ]],
        ];

        $html = self::render($doc);
        $this->assertStringContainsString('color:#abcdef', $html);
    }

    // ─── test_12: unknown block type fallback ───────────────────────────────

    public function test_12_unknown_block_type_falls_back_safely(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'weirdCustomBlock',
                'attrs' => [],
                'content' => [['type' => 'text', 'text' => 'fallback']],
            ]],
        ];

        $html = self::render($doc);
        $this->assertStringContainsString('fallback', $html);
    }

    // ─── test_13: table with header and body ────────────────────────────────

    public function test_13_renders_table_with_header_and_body(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'table',
                'content' => [
                    [
                        'type' => 'tableRow',
                        'content' => [
                            [
                                'type' => 'tableHeader',
                                'attrs' => ['colspan' => 1, 'rowspan' => 1],
                                'content' => [[
                                    'type' => 'paragraph',
                                    'content' => [['type' => 'text', 'text' => 'Módulo']],
                                ]],
                            ],
                            [
                                'type' => 'tableHeader',
                                'attrs' => ['colspan' => 1, 'rowspan' => 1],
                                'content' => [[
                                    'type' => 'paragraph',
                                    'content' => [['type' => 'text', 'text' => 'Nota']],
                                ]],
                            ],
                        ],
                    ],
                    [
                        'type' => 'tableRow',
                        'content' => [
                            [
                                'type' => 'tableCell',
                                'attrs' => ['colspan' => 1, 'rowspan' => 1],
                                'content' => [[
                                    'type' => 'paragraph',
                                    'content' => [['type' => 'text', 'text' => 'Programación']],
                                ]],
                            ],
                            [
                                'type' => 'tableCell',
                                'attrs' => ['colspan' => 1, 'rowspan' => 1],
                                'content' => [[
                                    'type' => 'paragraph',
                                    'content' => [['type' => 'text', 'text' => 'Notable']],
                                ]],
                            ],
                        ],
                    ],
                ],
            ]],
        ];

        $html = self::render($doc);
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<th>', $html);
        $this->assertStringContainsString('Módulo', $html);
        $this->assertStringContainsString('<td>', $html);
        $this->assertStringContainsString('Programación', $html);
    }

    // ─── test_14: empty table ───────────────────────────────────────────────

    public function test_14_empty_table_produces_empty_output(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [['type' => 'table', 'content' => []]],
        ];

        $this->assertSame('', self::render($doc));
    }

    // ─── test_15: nested bullet list ───────────────────────────────────────

    public function test_15_nested_bullet_list(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'bulletList',
                'content' => [
                    [
                        'type' => 'listItem',
                        'attrs' => [],
                        'content' => [
                            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'outer']]],
                            [
                                'type' => 'bulletList',
                                'content' => [[
                                    'type' => 'listItem',
                                    'attrs' => [],
                                    'content' => [[
                                        'type' => 'paragraph',
                                        'content' => [['type' => 'text', 'text' => 'inner']],
                                    ]],
                                ]],
                            ],
                        ],
                    ],
                ],
            ]],
        ];

        $html = self::render($doc);
        $this->assertStringContainsString('outer', $html);
        $this->assertStringContainsString('inner', $html);
        $this->assertMatchesRegularExpression('/<ul>.*<ul>.*inner.*<\/ul>.*<\/ul>/s', $html);
    }

    // ─── test_16: checked task list ──────────────────────────────────────────

    public function test_16_checkbox_data_preserved(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'taskList',
                'content' => [[
                    'type' => 'taskItem',
                    'attrs' => ['checked' => true],
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => [['type' => 'text', 'text' => 'done']],
                    ]],
                ]],
            ]],
        ];

        $html = self::render($doc);
        $this->assertStringContainsString('class="checklist"', $html);
        $this->assertStringContainsString('checked', $html);
        $this->assertStringContainsString('done', $html);
    }

    // ─── test_17: XSS in image URL ──────────────────────────────────────────

    public function test_17_dangerous_image_src_is_dropped(): void
    {
        // Quote-injection / non-URL garbage → image is dropped entirely.
        $bad = self::render([
            'type' => 'doc',
            'content' => [[
                'type' => 'image',
                'attrs' => ['src' => '" onerror="alert(1)', 'alt' => '', 'caption' => 'cap'],
            ]],
        ]);
        $this->assertStringNotContainsString('onerror=', $bad);
        $this->assertStringNotContainsString('<img', $bad);

        // javascript: image src is also dropped.
        $js = self::render([
            'type' => 'doc',
            'content' => [['type' => 'image', 'attrs' => ['src' => 'javascript:alert(1)']]],
        ]);
        $this->assertStringNotContainsString('<img', $js);

        // Legitimate srcs (https + inline data:image) still render.
        $https = self::render([
            'type' => 'doc',
            'content' => [['type' => 'image', 'attrs' => ['src' => 'https://example.com/a.png', 'alt' => 'a']]],
        ]);
        $this->assertStringContainsString('<img src="https://example.com/a.png"', $https);

        $dataImg = self::render([
            'type' => 'doc',
            'content' => [['type' => 'image', 'attrs' => ['src' => 'data:image/png;base64,iVBORw0KGgo=']]],
        ]);
        $this->assertStringContainsString('data:image/png;base64', $dataImg);
    }

    // ─── test_18: list item with bold + code ────────────────────────────────

    public function test_18_list_item_with_multiple_marks(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'bulletList',
                'content' => [[
                    'type' => 'listItem',
                    'attrs' => [],
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => [[
                            'type' => 'text',
                            'text' => 'bold',
                            'marks' => [
                                ['type' => 'bold'],
                                ['type' => 'code'],
                            ],
                        ]],
                    ]],
                ]],
            ]],
        ];

        $html = self::render($doc);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<code>', $html);
    }

    // ─── test_19: colspan cast to numeric, onclick blocked ──────────────────

    public function test_19_colspan_and_rowspan_are_numeric_only(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'table',
                'content' => [[
                    'type' => 'tableRow',
                    'content' => [[
                        'type' => 'tableHeader',
                        'attrs' => ['colspan' => 2, 'rowspan' => 1],
                        'content' => [[
                            'type' => 'paragraph',
                            'content' => [['type' => 'text', 'text' => 'h']],
                        ]],
                    ]],
                ]],
            ]],
        ];

        $html = self::render($doc);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertMatchesRegularExpression('/colspan="2"/', $html);
    }

    // ─── test_20: data: protocol blocked ────────────────────────────────────

    public function test_20_data_url_protocol_blocked_in_link(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'attrs' => [],
                'content' => [[
                    'type' => 'text',
                    'text' => 'x',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => 'data:text/html,<script>alert(1)</script>']]],
                ]],
            ]],
        ];

        $html = self::render($doc);
        $this->assertStringContainsString('href=""', $html);
        $this->assertStringNotContainsString('data:', $html);
    }

    // ─── test_21: vbscript: protocol blocked ────────────────────────────────

    public function test_21_vbscript_protocol_blocked_in_link(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'attrs' => [],
                'content' => [[
                    'type' => 'text',
                    'text' => 'x',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => 'vbscript:msgbox(1)']]],
                ]],
            ]],
        ];

        $html = self::render($doc);
        $this->assertStringContainsString('href=""', $html);
        $this->assertStringNotContainsString('vbscript:', $html);
    }

    // ─── test_22: safe protocols allowed ────────────────────────────────────

    public function test_22_https_and_http_protocols_allowed(): void
    {
        $validUrls = [
            'https://example.com',
            'http://example.com',
            'mailto:user@example.com',
            'tel:+1234567890',
            '#anchor',
            '/relative/path',
            './current/path',
            '../parent/path',
        ];

        foreach ($validUrls as $url) {
            $doc = [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'attrs' => [],
                    'content' => [[
                        'type' => 'text',
                        'text' => 'link',
                        'marks' => [['type' => 'link', 'attrs' => ['href' => $url]]],
                    ]],
                ]],
            ];

            $html = self::render($doc);
            $escaped = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertStringContainsString('href="'.$escaped.'"', $html, "URL $url should be preserved");
        }
    }
}
