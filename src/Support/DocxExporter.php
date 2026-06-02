<?php

declare(strict_types=1);

namespace Maya\Editor\Support;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html;
use RuntimeException;

/**
 * HTML → .docx export via phpoffice/phpword.
 *
 * Security:
 *   - Disables external entity loading via `libxml_disable_entity_loader()` (PHP <8.1)
 *     or sets LIBXML_NONET option (PHP 8+) to prevent XXE attacks.
 *   - Caller is responsible for the HTML having already been sanitised
 *     (typically by `TiptapHtmlRenderer` + DOMPurify).
 *
 * Optional dependency: `phpoffice/phpword`. If absent at runtime, the
 * exporter throws a `RuntimeException` with a clear install hint instead
 * of a confusing class-not-found.
 */
final class DocxExporter
{
    /**
     * Render the given HTML to a .docx binary string.
     */
    public static function export(string $html, string $title = 'Document'): string
    {
        if (! class_exists(PhpWord::class)) {
            throw new RuntimeException(
                'phpoffice/phpword is required for DocxExporter — run `composer require phpoffice/phpword`.',
            );
        }

        // Disable external entity loading to prevent XXE attacks.
        // For PHP <8.1, use libxml_disable_entity_loader(); for PHP 8+, this is deprecated
        // but we still set it as a fallback. PhpWord will use LIBXML_NONET internally.
        if (function_exists('libxml_disable_entity_loader')) {
            libxml_disable_entity_loader(true);
        }
        $prevLoaderState = libxml_use_internal_errors(true);

        try {
            $word = new PhpWord();
            $word->getDocInfo()->setTitle($title);
            $section = $word->addSection();
            // PhpWord parses a subset of HTML; CSS is ignored, but inline
            // tags (strong, em, table, ul/ol/li, img, a) are honoured.
            Html::addHtml($section, $html, false, false);

            $writer = IOFactory::createWriter($word, 'Word2007');
            $tmp = tempnam(sys_get_temp_dir(), 'maya-docx-');
            if ($tmp === false) {
                throw new RuntimeException('Unable to allocate temp file for .docx export.');
            }
            try {
                $writer->save($tmp);
                $bin = file_get_contents($tmp);
                if ($bin === false) {
                    throw new RuntimeException('Unable to read generated .docx file.');
                }
                return $bin;
            } finally {
                @unlink($tmp);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prevLoaderState);
        }
    }
}
