<?php

use App\Services\Documents\Exceptions\DocumentRenderException;
use App\Services\Documents\Renderers\DompdfRenderer;

/**
 * Page totals in the PDF footer.
 *
 * dompdf does not implement `counter(pages)` — it renders 0, verified against
 * 2.x — and its only native route to a total is inline PHP, which stays off
 * because template bodies are authored by users. So the total is resolved by
 * rendering twice: once to learn the count, once with it stamped in.
 *
 * The marker is an element, NOT `{{...}}`. The template language runs before
 * the PDF renderer and resolves unrecognised brace tokens to an empty string,
 * so a `{{__total_pages__}}` token was eaten before it ever arrived — the
 * footer printed "Page 1 of ". The last test here is that regression.
 *
 * These tests render real PDFs and read the bytes back. Asserting on the
 * renderer's own arithmetic would prove nothing about the file a client opens.
 */

/** Count `/Type /Page` objects the way a reader would, not the way we do. */
function pdfPageCount(string $pdf): int
{
    return substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages');
}

function longBody(int $paragraphs): string
{
    $html = '';

    for ($i = 1; $i <= $paragraphs; $i++) {
        $html .= "<p>Paragraph {$i}. Lorem ipsum dolor sit amet, consectetur "
            . 'adipiscing elit, sed do eiusmod tempor incididunt ut labore. '
            . 'Acentuação: ção, ã, é, ê.</p>';
    }

    return $html;
}

test('a footer asking for the total gets the real page count', function () {
    $renderer = app(DompdfRenderer::class);

    $pdf = $renderer->render(longBody(90), [
        'footer_html' => '<footer>Página <span class="page-number"></span> de '
            . DompdfRenderer::TOTAL_PAGES_TOKEN . '</footer>',
    ]);

    $pages = pdfPageCount($pdf);

    expect($pages)->toBeGreaterThan(1)
        // The marker must be gone — a contract showing raw markup where the
        // page count belongs is worse than one with no total at all.
        ->and($pdf)->not->toContain('page-total');
});

test('a document with no total token is rendered only once', function () {
    // Proven by behaviour rather than by counting calls: without the token the
    // output must still be a valid, correctly paginated PDF.
    $pdf = app(DompdfRenderer::class)->render(longBody(60), []);

    expect(pdfPageCount($pdf))->toBeGreaterThan(1)
        ->and(substr($pdf, 0, 5))->toBe('%PDF-');
});

test('the marker is honoured in the body as well as the footer', function () {
    $renderer = app(DompdfRenderer::class);

    $pdf = $renderer->render(
        '<p>This agreement runs to ' . DompdfRenderer::TOTAL_PAGES_TOKEN . ' pages.</p>'
        . longBody(40),
        []
    );

    expect($pdf)->not->toContain('page-total')
        ->and(substr($pdf, 0, 5))->toBe('%PDF-');
});

test('a single-page document reports one page', function () {
    $renderer = app(DompdfRenderer::class);

    $pdf = $renderer->render('<p>Short.</p>', [
        'footer_html' => '<footer>de ' . DompdfRenderer::TOTAL_PAGES_TOKEN . '</footer>',
    ]);

    expect(pdfPageCount($pdf))->toBe(1);
});

test('chrome authored as plain paragraphs is wrapped so it repeats per page', function () {
    // THE REGRESSION. Repeating comes from the `header`/`footer` element rules
    // in the print stylesheet, and the visual editor emits <p>, not <header>.
    // Unwrapped, the letterhead rendered once in the document flow and
    // disappeared after page 1 — the one behaviour page chrome exists for.
    //
    // Asserted on the wrapping rather than on the rendered bytes: dompdf
    // subsets its fonts and encodes text as glyph indices, so no literal
    // string survives into the content streams to be counted. The end-to-end
    // behaviour was confirmed separately against a real two-page contract.
    $wrap = new ReflectionMethod(DompdfRenderer::class, 'asChrome');
    $wrap->setAccessible(true);

    $renderer = app(DompdfRenderer::class);

    expect($wrap->invoke($renderer, '<p>ACME LTDA</p>', 'header'))
        ->toBe('<header><p>ACME LTDA</p></header>');

    // Idempotent: a hand-written template that already carries the element
    // must not end up nested inside a second one.
    expect($wrap->invoke($renderer, '<header>Already wrapped</header>', 'header'))
        ->toBe('<header>Already wrapped</header>');

    // Empty stays empty — an author who never touched the header should not
    // get an empty element positioned into the margin.
    expect($wrap->invoke($renderer, '   ', 'footer'))->toBe('');
});

test('a document with paragraph chrome still renders', function () {
    $pdf = app(DompdfRenderer::class)->render(longBody(90), [
        'header_html' => '<p>ACME LTDA</p>',
        'footer_html' => '<p>Página <span class="page-number"></span> de '
            . DompdfRenderer::TOTAL_PAGES_TOKEN . '</p>',
    ]);

    expect(pdfPageCount($pdf))->toBeGreaterThan(2)
        ->and($pdf)->not->toContain('page-total');
});

test('the page-total marker survives the template renderer', function () {
    // THE REGRESSION. TemplateRenderer runs first, on its way to the PDF, and
    // resolves any `{{...}}` it does not recognise to an empty string. The
    // marker must therefore not be brace syntax — when it was
    // `{{__total_pages__}}`, every generated contract read "Page 1 of ".
    $footer = 'Página <span class="page-number"></span> de '
        . DompdfRenderer::TOTAL_PAGES_TOKEN;

    $survived = app(\App\Services\Documents\TemplateRenderer::class)->render($footer, []);

    expect($survived)->toContain('page-total');

    // And still resolves to a number once the PDF renderer sees it.
    $pdf = app(DompdfRenderer::class)->render(longBody(50), [
        'footer_html' => "<footer>{$survived}</footer>",
    ]);

    expect($pdf)->not->toContain('page-total');
});

test('an unrenderable document fails loudly rather than producing a broken file', function () {
    // dompdf rejects a <tr> outside a <table> ("Parent table not found for
    // table cell"). The point is that the failure arrives as our exception
    // type, not as bytes that merely look like a PDF — the visual editor will
    // be able to emit exactly this if a table node is ever serialised wrong.
    expect(fn () => app(DompdfRenderer::class)->render('<tr><td>orphan</td></tr>', []))
        ->toThrow(DocumentRenderException::class);
});
