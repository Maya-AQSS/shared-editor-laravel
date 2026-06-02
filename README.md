# shared-editor-laravel

Server-side renderers for TipTap/ProseMirror JSON documents.

## Components

- **`Maya\Editor\Renderers\TiptapHtmlRenderer`** — Converts a ProseMirror JSON doc to safe HTML. Used for PDF/UA generation and Blade SSR previews. Domain-agnostic; accepts any ProseMirror document.
- **`Maya\Editor\Renderers\BlockNoteToTiptap`** — Converts legacy BlockNote JSON to ProseMirror JSON. Used during the BlockNote → TipTap migration.
- **`Maya\Editor\Support\DocxExporter`** — HTML to .docx export via `phpoffice/phpword` (optional dependency).

## Usage

```php
use Maya\Editor\Renderers\TiptapHtmlRenderer;

$html = TiptapHtmlRenderer::renderDoc($tiptapJson);
```

Migration from legacy BlockNote:

```php
use Maya\Editor\Renderers\BlockNoteToTiptap;
use Maya\Editor\Renderers\TiptapHtmlRenderer;

$tiptapDoc = BlockNoteToTiptap::convert($blockNoteJson);
$html = TiptapHtmlRenderer::renderDoc($tiptapDoc);
```

## Security

- Strict HTML escaping via `htmlspecialchars` (no `e()` Laravel helper — package is framework-agnostic at render time).
- Color values whitelisted to named colors / hex.
- `colspan`/`rowspan` cast to `int` before rendering.
- Image URLs are escaped as attribute values but **not** scheme-restricted — callers should add CSP (`script-src 'none'`) for defence in depth.
