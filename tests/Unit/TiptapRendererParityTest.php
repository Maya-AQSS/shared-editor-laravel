<?php

declare(strict_types=1);

use Maya\Editor\Renderers\TiptapHtmlRenderer;
use Tests\Support\Fingerprint;

/**
 * Parity oracle (PHP side). Renders the shared TipTap fixtures via
 * TiptapHtmlRenderer and asserts the SEMANTIC fingerprint matches the contract
 * committed by the JS static renderer (`fingerprints.json`). Catches content /
 * structure drift between the CSR preview and the server-side PDF/DOCX renderer.
 *
 * Regenerate the contract from JS after intentional changes:
 *   UPDATE_FP=1 pnpm vitest run src/parity/parity.test.ts   (in shared-editor-react)
 */
function parityFixtures(): array
{
    $dir = __DIR__.'/../fixtures/tiptap';
    $fixtures = json_decode((string) file_get_contents($dir.'/fixtures.json'), true);
    $expected = json_decode((string) file_get_contents($dir.'/fingerprints.json'), true);

    $cases = [];
    foreach ($fixtures as $name => $doc) {
        $cases[$name] = [$doc, $expected[$name] ?? null];
    }

    return $cases;
}

it('renders fixtures with the same semantic fingerprint as the JS static renderer', function (array $doc, ?array $expected) {
    expect($expected)->not->toBeNull('missing JS fingerprint — regenerate fingerprints.json');

    $html = TiptapHtmlRenderer::renderDoc($doc);

    expect(Fingerprint::of($html))->toEqual($expected);
})->with('parityFixtures');

dataset('parityFixtures', parityFixtures());
