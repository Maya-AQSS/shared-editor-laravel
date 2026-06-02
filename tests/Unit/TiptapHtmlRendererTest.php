<?php

declare(strict_types=1);

namespace Tests\Unit;

use Maya\Editor\Renderers\BlockNoteToTiptap;
use Maya\Editor\Renderers\TiptapHtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Oracle parity tests: every case feeds the SAME BlockNote JSON input to
 *   (a) the legacy mental model (asserted HTML strings)
 *   (b) BlockNoteToTiptap::convert → TiptapHtmlRenderer::renderDoc
 * The two outputs must be functionally equivalent (the renderer mirrors
 * what the legacy `BlockNoteHtmlRenderer` produced, modulo benign markup
 * variations explicitly noted per case).
 */
final class TiptapHtmlRendererTest extends TestCase
{
    private static function renderFromBlockNote(array $blocks): string
    {
        $doc = BlockNoteToTiptap::convert($blocks);

        return TiptapHtmlRenderer::renderDoc($doc);
    }

    // ─── 14 ported from BlockNoteHtmlRendererTest ─────────────────────────

    public function test_01_renders_empty_array_to_empty_string(): void
    {
        $this->assertSame('', self::renderFromBlockNote([]));
    }

    public function test_02_renders_paragraph_with_inline_text(): void
    {
        $html = self::renderFromBlockNote([[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Hola mundo', 'styles' => []]],
        ]]);

        $this->assertStringContainsString('<p>Hola mundo</p>', $html);
    }

    public function test_03_renders_heading_levels_1_to_6(): void
    {
        foreach ([1, 2, 3, 4, 5, 6] as $level) {
            $html = self::renderFromBlockNote([[
                'type' => 'heading',
                'props' => ['level' => $level],
                'content' => [['type' => 'text', 'text' => 'T'.$level, 'styles' => []]],
            ]]);

            $this->assertStringContainsString('<h'.$level.'>T'.$level.'</h'.$level.'>', $html);
        }
    }

    public function test_04_clamps_invalid_heading_level(): void
    {
        $html = self::renderFromBlockNote([[
            'type' => 'heading',
            'props' => ['level' => 99],
            'content' => [['type' => 'text', 'text' => 'X', 'styles' => []]],
        ]]);

        $this->assertStringContainsString('<h6>X</h6>', $html);
    }

    public function test_05_escapes_html_in_user_text(): void
    {
        $html = self::renderFromBlockNote([[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => '<script>alert("xss")</script>',
                'styles' => [],
            ]],
        ]]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_06_applies_bold_italic_underline_marks(): void
    {
        $html = self::renderFromBlockNote([[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'text',
                'text' => 'foo',
                'styles' => ['bold' => true, 'italic' => true, 'underline' => true],
            ]],
        ]]);

        // Mark order in TiptapHtmlRenderer: bold → italic → underline (outer-first when wrapping).
        // Outer wrap is the LAST applied → underline outermost.
        $this->assertStringContainsString('<u><em><strong>foo</strong></em></u>', $html);
    }

    public function test_07_renders_bullet_list_item(): void
    {
        $html = self::renderFromBlockNote([[
            'type' => 'bulletListItem',
            'content' => [['type' => 'text', 'text' => 'item1', 'styles' => []]],
        ]]);

        $this->assertStringContainsString('<ul><li>item1</li></ul>', $html);
    }

    public function test_08_renders_link(): void
    {
        $html = self::renderFromBlockNote([[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'link',
                'href' => 'https://example.com',
                'content' => [['type' => 'text', 'text' => 'click', 'styles' => []]],
            ]],
        ]]);

        $this->assertStringContainsString('<a href="https://example.com">click</a>', $html);
    }

    public function test_09_blocks_javascript_protocol_in_link(): void
    {
        $html = self::renderFromBlockNote([[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'link',
                'href' => 'javascript:alert(1)',
                'content' => [['type' => 'text', 'text' => 'x', 'styles' => []]],
            ]],
        ]]);

        // javascript: protocol is blocked; href becomes empty string
        $this->assertStringContainsString('href=""', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_10_sanitizes_color_to_hex_or_named_only(): void
    {
        $html = self::renderFromBlockNote([[
            'type' => 'paragraph',
            'props' => ['textColor' => 'expression(alert(1))'],
            'content' => [['type' => 'text', 'text' => 'x', 'styles' => []]],
        ]]);

        $this->assertStringNotContainsString('expression', $html);
        $this->assertStringContainsString('color:inherit', $html);
    }

    public function test_11_accepts_valid_hex_color(): void
    {
        $html = self::renderFromBlockNote([[
            'type' => 'paragraph',
            'props' => ['textColor' => '#abcdef'],
            'content' => [['type' => 'text', 'text' => 'x', 'styles' => []]],
        ]]);

        $this->assertStringContainsString('color:#abcdef', $html);
    }

    public function test_12_unknown_block_type_falls_back_safely(): void
    {
        $html = self::renderFromBlockNote([[
            'type' => 'weirdCustomBlock',
            'content' => [['type' => 'text', 'text' => 'fallback', 'styles' => []]],
        ]]);

        // Tiptap converter wraps unknown blocks in a paragraph with data attr.
        // Renderer outputs them as paragraphs; the text content survives.
        $this->assertStringContainsString('fallback', $html);
    }

    public function test_13_renders_table_with_header_and_body(): void
    {
        $html = self::renderFromBlockNote([[
            'type' => 'table',
            'content' => [
                'rows' => [
                    ['cells' => [
                        [['type' => 'text', 'text' => 'Módulo', 'styles' => []]],
                        [['type' => 'text', 'text' => 'Nota', 'styles' => []]],
                    ]],
                    ['cells' => [
                        [['type' => 'text', 'text' => 'Programación', 'styles' => []]],
                        [['type' => 'text', 'text' => 'Notable', 'styles' => []]],
                    ]],
                ],
            ],
        ]]);

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<th>', $html);
        $this->assertStringContainsString('Módulo', $html);
        $this->assertStringContainsString('<td>', $html);
        $this->assertStringContainsString('Programación', $html);
    }

    public function test_14_empty_table_produces_empty_output(): void
    {
        $html = self::renderFromBlockNote([[
            'type' => 'table',
            'content' => ['rows' => []],
        ]]);

        $this->assertSame('', $html);
    }

    // ─── 5 additional edge cases (council recommendation) ─────────────────

    public function test_15_nested_bullet_list(): void
    {
        $html = self::renderFromBlockNote([[
            'type' => 'bulletListItem',
            'content' => [['type' => 'text', 'text' => 'outer', 'styles' => []]],
            'children' => [[
                'type' => 'bulletListItem',
                'content' => [['type' => 'text', 'text' => 'inner', 'styles' => []]],
            ]],
        ]]);

        $this->assertStringContainsString('outer', $html);
        $this->assertStringContainsString('inner', $html);
        // Nested list nesting present.
        $this->assertMatchesRegularExpression('/<ul>.*<ul>.*inner.*<\/ul>.*<\/ul>/s', $html);
    }

    public function test_16_checkbox_data_preserved(): void
    {
        $html = self::renderFromBlockNote([[
            'type' => 'checkListItem',
            'props' => ['checked' => true],
            'content' => [['type' => 'text', 'text' => 'done', 'styles' => []]],
        ]]);

        $this->assertStringContainsString('class="checklist"', $html);
        $this->assertStringContainsString('checked', $html);
        $this->assertStringContainsString('done', $html);
    }

    public function test_17_xss_in_image_url_is_escaped(): void
    {
        $html = self::renderFromBlockNote([[
            'type' => 'image',
            'props' => ['url' => '" onerror="alert(1)', 'caption' => 'cap'],
        ]]);

        $this->assertStringNotContainsString('onerror=', $html);
        // The escaped attribute uses HTML5 quote escaping (&quot;).
        $this->assertMatchesRegularExpression('/src="&quot;[^"]*"/', $html);
    }

    public function test_18_list_item_with_multiple_marks(): void
    {
        $html = self::renderFromBlockNote([[
            'type' => 'bulletListItem',
            'content' => [[
                'type' => 'text',
                'text' => 'bold',
                'styles' => ['bold' => true, 'code' => true],
            ]],
        ]]);

        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<code>', $html);
    }

    public function test_19_colspan_and_rowspan_are_numeric_only(): void
    {
        $html = self::renderFromBlockNote([[
            'type' => 'table',
            'content' => [
                'rows' => [
                    ['cells' => [
                        [
                            'content' => [['type' => 'text', 'text' => 'h', 'styles' => []]],
                            'props' => ['colspan' => '2" onclick="x', 'rowspan' => 1],
                        ],
                    ]],
                ],
            ],
        ]]);

        // colspan was cast to int → "2" only.
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertMatchesRegularExpression('/colspan="2"/', $html);
    }

    public function test_20_data_url_protocol_blocked_in_link(): void
    {
        $html = self::renderFromBlockNote([[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'link',
                'href' => 'data:text/html,<script>alert(1)</script>',
                'content' => [['type' => 'text', 'text' => 'x', 'styles' => []]],
            ]],
        ]]);

        // data: protocol is blocked; href becomes empty string
        $this->assertStringContainsString('href=""', $html);
        $this->assertStringNotContainsString('data:', $html);
    }

    public function test_21_vbscript_protocol_blocked_in_link(): void
    {
        $html = self::renderFromBlockNote([[
            'type' => 'paragraph',
            'content' => [[
                'type' => 'link',
                'href' => 'vbscript:msgbox(1)',
                'content' => [['type' => 'text', 'text' => 'x', 'styles' => []]],
            ]],
        ]]);

        // vbscript: protocol is blocked; href becomes empty string
        $this->assertStringContainsString('href=""', $html);
        $this->assertStringNotContainsString('vbscript:', $html);
    }

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
            $html = self::renderFromBlockNote([[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'link',
                    'href' => $url,
                    'content' => [['type' => 'text', 'text' => 'link', 'styles' => []]],
                ]],
            ]]);

            $escaped = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertStringContainsString('href="'.$escaped.'"', $html, "URL $url should be preserved");
        }
    }
}
