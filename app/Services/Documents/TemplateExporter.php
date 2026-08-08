<?php

namespace App\Services\Documents;

use App\Models\DocumentTemplate;
use App\Services\Documents\Contracts\PdfRenderer;
use App\Services\Documents\Exceptions\DocumentRenderException;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html as PhpWordHtml;
use PhpOffice\PhpWord\Style\Language;
use PhpOffice\PhpWord\Writer\Word2007;

/**
 * A template, as a file somebody can open.
 *
 * This is for the author, not the customer: taking the wording to a lawyer,
 * mailing a draft, keeping an offline copy. It renders the *template*, so what
 * comes out still contains `{{lead.name}}` unless a record is supplied to
 * resolve against — that is the point, since the thing being reviewed is the
 * template rather than any one contract made from it.
 *
 * PDF goes through the same renderer as real contracts, so an exported draft
 * looks exactly like the finished document. DOCX is a genuine Word file via
 * PHPWord — not HTML with the extension changed, which Word opens but every
 * other tool, and Word's own track-changes, treat as broken.
 */
class TemplateExporter
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly VariableResolver $resolver,
        private readonly DocumentAssetService $assets,
    ) {
    }

    /**
     * @param array<string, mixed> $context Resolved variables, or [] to keep
     *                                      the tokens visible in the output.
     */
    public function toPdf(DocumentTemplate $template, array $context = []): string
    {
        $html = $this->assets->inlineAssets(
            $this->renderer->render($template->body_html ?? '', $context)
        );

        return app(PdfRenderer::class)->render($html, array_merge(
            $template->pdfOptions(),
            [
                'title'       => $template->name,
                'header_html' => $template->header_html
                    ? $this->assets->inlineAssets($this->renderer->render($template->header_html, $context))
                    : null,
                'footer_html' => $template->footer_html
                    ? $this->assets->inlineAssets($this->renderer->render($template->footer_html, $context))
                    : null,
            ]
        ));
    }

    /**
     * @param array<string, mixed> $context
     * @throws DocumentRenderException
     */
    public function toWord(DocumentTemplate $template, array $context = []): string
    {
        $html = $this->assets->inlineAssets(
            $this->renderer->render($template->body_html ?? '', $context)
        );

        $word = new PhpWord();

        // Language drives Word's spellchecker and its date/number handling.
        // A Portuguese contract flagged as English is underlined end to end.
        $word->getSettings()->setThemeFontLang(new Language(Language::PT_BR));

        $settings = $template->pageSettings();

        $section = $word->addSection($this->sectionStyle($settings));

        try {
            /*
             * `addHtml` is strict: it throws on markup it cannot map, and its
             * subset does not cover everything the visual editor can produce.
             * Wrapped so an unsupported construct becomes an explained failure
             * rather than a 500, since the PDF route is unaffected and the
             * author can still use it.
             */
            PhpWordHtml::addHtml($section, $this->wordSafeHtml($html), false, false);
        } catch (\Throwable $e) {
            throw new DocumentRenderException(
                'Não foi possível converter este modelo para Word: ' . $e->getMessage()
                . ' Exporte em PDF ou simplifique a formatação.'
            );
        }

        $writer = new Word2007($word);

        // Streamed to a temp file: PHPWord writes a ZIP archive, which it can
        // only do to a real path, and the result must not sit in memory twice.
        $path = tempnam(sys_get_temp_dir(), 'tpl') ?: throw new DocumentRenderException(
            'Não foi possível criar o arquivo temporário do Word.'
        );

        try {
            $writer->save($path);

            return file_get_contents($path) ?: '';
        } finally {
            @unlink($path);
        }
    }

    /** Page setup, translated from millimetres into Word's twips. */
    private function sectionStyle(array $settings): array
    {
        $mmToTwip = fn (float $mm) => (int) round($mm * 56.6929);

        $paper = strtoupper($settings['paper'] ?? 'a4');

        return [
            'paperSize'    => in_array($paper, ['A4', 'LETTER', 'LEGAL', 'A3', 'A5'], true) ? $paper : 'A4',
            'orientation'  => $settings['orientation'] === 'landscape' ? 'landscape' : 'portrait',
            'marginTop'    => $mmToTwip((float) $settings['margins']['top']),
            'marginRight'  => $mmToTwip((float) $settings['margins']['right']),
            'marginBottom' => $mmToTwip((float) $settings['margins']['bottom']),
            'marginLeft'   => $mmToTwip((float) $settings['margins']['left']),
        ];
    }

    /**
     * Trim the HTML down to what PHPWord's parser accepts.
     *
     * Its converter is a subset, not a browser. Two constructs the visual
     * editor emits reliably break it, and both have a clean equivalent:
     *
     *   - the page-break div, which becomes a real Word page break
     *   - the page-number/page-total markers, which have no meaning outside
     *     our PDF pipeline and would otherwise print as empty spans
     */
    private function wordSafeHtml(string $html): string
    {
        // PHPWord's parser reads page breaks from CSS, not from Word's own
        // markup: `w:br` fails outright, because the HTML is parsed by
        // DOMDocument where the `w:` namespace does not exist.
        $html = preg_replace(
            '~<div\b[^>]*class="[^"]*\bpage-break\b[^"]*"[^>]*>\s*</div>~i',
            '<p style="page-break-after: always"></p>',
            $html
        ) ?? $html;

        // PHPWord has no concept of these; Word's own field codes would be the
        // correct target, and are worth adding only if somebody asks.
        $html = preg_replace(
            '~<span\b[^>]*class="[^"]*\b(page-number|page-total)\b[^"]*"[^>]*>\s*</span>~i',
            '',
            $html
        ) ?? $html;

        return $this->toWellFormedXml($html);
    }

    /**
     * Re-emit the HTML as well-formed XML.
     *
     * PHPWord loads the markup with `DOMDocument::loadXML`, which is strict:
     * the void elements every rich-text editor produces — `<br>`, `<hr>`,
     * `<img>` — are HTML-valid and XML-fatal, and one of them aborts the whole
     * export. Regex-patching each tag would be a game of whack-a-mole, so the
     * document is parsed in lenient HTML mode and serialised back as XML,
     * which closes every void element and normalises the entities in one pass.
     */
    private function toWellFormedXml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');

        // Editor output is not required to be valid HTML; warnings about it
        // are noise, and the parser recovers from all of it.
        $previous = libxml_use_internal_errors(true);

        $dom->loadHTML(
            '<?xml encoding="UTF-8"?><div id="root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('root') ?? $dom->documentElement;

        if (!$root) {
            return $html;
        }

        $xml = '';

        foreach ($root->childNodes as $child) {
            $xml .= $dom->saveXML($child);
        }

        return $xml;
    }

    /**
     * Variables resolved against a real record, or an empty context.
     *
     * Mirrors the preview endpoint: an unsaved, unnumbered Document is built
     * purely as the subject VariableResolver needs. Nothing is persisted, so
     * exporting a draft neither creates a contract nor burns a contract number.
     */
    public function contextFor(DocumentTemplate $template, ?int $leadId, ?int $saleId): array
    {
        if (!$leadId && !$saleId) {
            return $this->echoContext($template);
        }

        $document = new \App\Models\Document([
            'company_id'       => $template->company_id,
            'document_type_id' => $template->document_type_id,
            'title'            => $template->name,
            'lead_id'          => $leadId,
            'sale_id'          => $saleId,
        ]);

        $document->number = 'PREVIEW';
        $document->exists = false;

        return $this->resolver->resolve($document);
    }

    /**
     * A context in which every variable resolves to its own name.
     *
     * Exported with no record to resolve against, `{{lead.name}}` would
     * otherwise come out as nothing at all — the renderer treats an absent
     * value as empty — leaving a contract full of unexplained gaps. Somebody
     * reviewing wording needs to see *where* the data goes, so each token is
     * mapped to a literal copy of itself.
     *
     * Built from `variables_schema.referenced`, which the controller already
     * computes on every save, so this stays in step with the body for free.
     */
    private function echoContext(DocumentTemplate $template): array
    {
        $referenced = $template->variables_schema['referenced'] ?? [];
        $context    = [];

        foreach ($referenced as $token) {
            if (!is_string($token) || $token === '') {
                continue;
            }

            // Loop variables (`item.*`) are bound by the loop, not the
            // context; echoing them would print the token inside a loop that
            // never runs anyway.
            if (str_starts_with($token, 'item.') || str_starts_with($token, 'loop.')) {
                continue;
            }

            data_set($context, $token, '{{' . $token . '}}');
        }

        return $context;
    }
}
