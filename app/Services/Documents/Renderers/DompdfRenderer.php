<?php

namespace App\Services\Documents\Renderers;

use App\Services\Documents\Contracts\PdfRenderer;
use App\Services\Documents\Exceptions\DocumentRenderException;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * PDF via dompdf — pure PHP, no system dependencies.
 *
 * The trade is CSS support: dompdf understands tables and basic block layout,
 * not flexbox or grid. Seeded templates are therefore table-based, which is
 * also what contracts have always looked like. When Node and Chromium exist in
 * the API image, a Browsershot driver replaces this class and nothing else
 * changes.
 *
 * Remote assets stay disabled unless explicitly configured on. Template bodies
 * are authored by users, and a renderer that fetches arbitrary URLs at render
 * time turns a contract template into a server-side request forgery primitive
 * against whatever the API container can reach.
 */
class DompdfRenderer implements PdfRenderer
{
    /**
     * The marker a footer writes to mean "the total number of pages".
     *
     * An HTML element, deliberately NOT `{{...}}`. The template language runs
     * first, and anything in double braces that it does not recognise is
     * resolved to an empty string — a `{{__total_pages__}}` token was silently
     * eaten before the PDF renderer ever saw it, producing "Page 1 of ".
     *
     * A marker element also matches how the current page number already works
     * (`<span class="page-number">`, filled by `counter(page)`), so the two
     * halves of "Page 1 of 5" are authored the same way.
     *
     * A marker at all — rather than `counter(pages)` — because dompdf does not
     * implement that counter; it silently renders 0, verified against 2.x. Its
     * only native route to a total is inline PHP, and `isPhpEnabled` must stay
     * off while template bodies are authored by users.
     */
    public const TOTAL_PAGES_TOKEN = '<span class="page-total"></span>';

    /** Tolerates attribute order and whitespace the editor may produce. */
    private const TOTAL_PAGES_PATTERN = '~<span\b[^>]*class="[^"]*\bpage-total\b[^"]*"[^>]*>\s*</span>~i';

    public function key(): string
    {
        return 'dompdf';
    }

    /**
     * Render, and resolve the page total by rendering twice when asked to.
     *
     * The count is not knowable until the document has been laid out, and
     * laying it out is the render. So: render once to learn the number,
     * substitute it, render again. The second pass can in principle repaginate
     * — "9" is wider than "8" — but only if the total sits in flowing text;
     * in a fixed-position footer, which is the only place it belongs, the
     * layout is identical. `assertStablePagination` checks that assumption
     * instead of trusting it.
     */
    public function render(string $html, array $options = []): string
    {
        $needsTotal = $this->wantsTotal($html)
            || $this->wantsTotal($options['footer_html'] ?? '')
            || $this->wantsTotal($options['header_html'] ?? '');

        if (!$needsTotal) {
            return $this->renderOnce($html, $options);
        }

        $firstPass = $this->renderOnce($html, $options);
        $total     = $this->countPages($firstPass);

        if ($total === null) {
            // Better a document with an empty total than no document at all.
            return $this->renderOnce(
                $this->substituteTotal($html, ''),
                $this->substituteInChrome($options, '')
            );
        }

        $secondPass = $this->renderOnce(
            $this->substituteTotal($html, (string) $total),
            $this->substituteInChrome($options, (string) $total)
        );

        $this->assertStablePagination($secondPass, $total);

        return $secondPass;
    }

    private function renderOnce(string $html, array $options): string
    {
        try {
            $pdf = Pdf::loadHTML($this->wrap($html, $options));

            $pdf->setPaper(
                $options['paper'] ?? config('documents.pdf.paper', 'a4'),
                $options['orientation'] ?? config('documents.pdf.orientation', 'portrait')
            );

            $pdf->setOptions([
                'isRemoteEnabled'      => (bool) config('documents.pdf.allow_remote_assets', false),
                'isHtml5ParserEnabled' => true,
                // No PHP evaluation inside the document, ever. dompdf can be
                // asked to run inline PHP; a user-authored contract body is
                // exactly the input that must never be given that.
                'isPhpEnabled'         => false,
                'defaultFont'          => 'DejaVu Sans',
            ]);

            return $pdf->output();
        } catch (\Throwable $e) {
            throw DocumentRenderException::pdf($e->getMessage());
        }
    }

    private function wantsTotal(?string $html): bool
    {
        return !empty($html) && preg_match(self::TOTAL_PAGES_PATTERN, $html) === 1;
    }

    private function substituteTotal(string $html, string $total): string
    {
        return preg_replace(self::TOTAL_PAGES_PATTERN, $total, $html) ?? $html;
    }

    /** @param array<string, mixed> $options */
    private function substituteInChrome(array $options, string $total): array
    {
        foreach (['header_html', 'footer_html'] as $key) {
            if (!empty($options[$key])) {
                $options[$key] = $this->substituteTotal($options[$key], $total);
            }
        }

        return $options;
    }

    /**
     * Page count straight from the PDF's own page tree.
     *
     * `/Type /Page` is counted rather than trusting `/Count`, which appears on
     * every intermediate node of the tree and would over-count on a document
     * dompdf split into several nodes.
     */
    private function countPages(string $pdf): ?int
    {
        $matches = preg_match_all('#/Type\s*/Page[^s]#', $pdf);

        return $matches > 0 ? $matches : null;
    }

    /**
     * A wrong total is worse than none — it is a number on a legal document
     * that does not match the document. If the second pass disagrees with what
     * we stamped, say so rather than shipping it.
     */
    private function assertStablePagination(string $pdf, int $stamped): void
    {
        $actual = $this->countPages($pdf);

        if ($actual !== null && $actual !== $stamped) {
            throw DocumentRenderException::pdf(
                "Pagination did not settle: the document was stamped as {$stamped} pages "
                . "but rendered as {$actual}. This happens when the page total sits in "
                . 'flowing content rather than a fixed header or footer.'
            );
        }
    }

    /**
     * Wrap the body in a print stylesheet.
     *
     * DejaVu Sans is dompdf's only bundled font with full Latin-1 coverage —
     * without it, accented Portuguese renders as boxes, which on a legal
     * document is not a cosmetic problem.
     */
    private function wrap(string $html, array $options): string
    {
        $title  = e($options['title'] ?? 'Documento');

        // Wrapped here, not by the author. Repeating on every page comes from
        // the `header`/`footer` element rules in the stylesheet below, and the
        // visual editor emits paragraphs — so chrome authored in it would
        // render once, in the flow, and vanish after page 1. Templates written
        // by hand may already carry the element, hence the check.
        $header = $this->asChrome($options['header_html'] ?? '', 'header');
        $footer = $this->asChrome($options['footer_html'] ?? '', 'footer');

        // Page setup, defaulted to DocumentTemplate::DEFAULT_PAGE_SETTINGS.
        // The editor canvas is driven from the same numbers, which is the only
        // reason what an author sees can match what a signer receives.
        $margins = $options['margins'] ?? ['top' => 25, 'right' => 20, 'bottom' => 30, 'left' => 20];
        $type    = $options['typography'] ?? [];

        $font      = $type['font_family'] ?? 'DejaVu Sans';
        $fontSize  = (float) ($type['font_size'] ?? 11);
        $lineHt    = (float) ($type['line_height'] ?? 1.6);
        $paraGap   = (float) ($type['paragraph_spacing'] ?? 10);

        $mTop    = (float) ($margins['top'] ?? 25);
        $mRight  = (float) ($margins['right'] ?? 20);
        $mBottom = (float) ($margins['bottom'] ?? 30);
        $mLeft   = (float) ($margins['left'] ?? 20);

        // Header and footer are positioned *into* the page margin. dompdf
        // measures `position: fixed` from the content box, so reaching the
        // margin means a negative offset — hence the subtraction rather than
        // a plain distance from the paper edge.
        $headerTop    = -1 * max(0, $mTop - (float) ($options['header_offset'] ?? 12));
        $footerBottom = -1 * max(0, $mBottom - (float) ($options['footer_offset'] ?? 15));

        // Whitelist: this string reaches a stylesheet, and the value arrives
        // from a user-editable settings panel. Anything unrecognised falls
        // back to the one font dompdf bundles with full Latin-1 coverage.
        $font = $this->safeFontStack($font);

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>{$title}</title>
<style>
    @page { margin: {$mTop}mm {$mRight}mm {$mBottom}mm {$mLeft}mm; }
    body {
        font-family: {$font};
        font-size: {$fontSize}pt;
        line-height: {$lineHt};
        color: #111;
    }
    h1 { font-size: 16pt; margin: 0 0 12pt; }
    h2 { font-size: 13pt; margin: 18pt 0 8pt; }
    h3 { font-size: 11pt; margin: 14pt 0 6pt; }
    p  { margin: 0 0 {$paraGap}pt; text-align: justify; }
    table { width: 100%; border-collapse: collapse; margin: 12pt 0; }
    th, td { border: 1px solid #999; padding: 6pt 8pt; text-align: left; }
    th { background: #f0f0f0; font-weight: bold; }
    td.numeric, th.numeric { text-align: right; }
    /* Repeat table headings on every page a long table spans. Verified
       working in dompdf 2.x; without it a product table's column titles
       appear only on the first page. */
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    img { max-width: 100%; }
    blockquote {
        margin: 10pt 0 10pt 16pt;
        padding-left: 10pt;
        border-left: 2pt solid #ccc;
        color: #444;
    }
    hr { border: none; border-top: 1px solid #bbb; margin: 14pt 0; }
    /* Signature blocks must not be split across a page break. */
    .signature-block { page-break-inside: avoid; margin-top: 28pt; }
    .page-break { page-break-after: always; }
    header { position: fixed; top: {$headerTop}mm; left: 0; right: 0; font-size: 9pt; color: #555; }
    footer { position: fixed; bottom: {$footerBottom}mm; left: 0; right: 0; font-size: 9pt; color: #555; }
    .page-number:after { content: counter(page); }
</style>
</head>
<body>
{$header}
{$footer}
{$html}
</body>
</html>
HTML;
    }

    /**
     * Ensure page chrome is inside the element the stylesheet fixes in place.
     *
     * Idempotent: a body that already opens with the tag is left alone, so a
     * hand-written template that included `<header>` does not end up nested.
     */
    private function asChrome(string $html, string $tag): string
    {
        $trimmed = trim($html);

        if ($trimmed === '') {
            return '';
        }

        return str_starts_with(strtolower($trimmed), "<{$tag}")
            ? $trimmed
            : "<{$tag}>{$trimmed}</{$tag}>";
    }

    /**
     * A font stack dompdf can actually resolve.
     *
     * dompdf ships four families; anything else silently falls back and, for
     * accented Portuguese, silently falls back to boxes. Returning a known
     * stack is therefore a correctness measure, not only an injection guard —
     * though it is that too, since the value reaches a stylesheet.
     */
    private function safeFontStack(string $requested): string
    {
        return match (strtolower(trim($requested))) {
            'helvetica', 'arial', 'sans-serif' => '"Helvetica", "DejaVu Sans", sans-serif',
            'times', 'times new roman', 'serif' => '"Times", "DejaVu Serif", serif',
            'courier', 'courier new', 'monospace' => '"Courier", "DejaVu Sans Mono", monospace',
            'dejavu serif' => '"DejaVu Serif", serif',
            'dejavu sans mono' => '"DejaVu Sans Mono", monospace',
            default => '"DejaVu Sans", sans-serif',
        };
    }
}
