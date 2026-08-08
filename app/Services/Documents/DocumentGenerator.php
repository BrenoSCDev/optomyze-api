<?php

namespace App\Services\Documents;

use App\Events\DocumentGenerated;
use App\Models\CompanyFile;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\Documents\Contracts\PdfRenderer;
use App\Services\Documents\Exceptions\DocumentRenderException;
use App\Services\Documents\Support\DocumentStatus;
use App\Services\Storage\CompanyStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Turns a document plus its template into a new immutable version.
 *
 * The sequence is: snapshot the template body → resolve variables against the
 * live records → render → produce a PDF → store it → write the version row →
 * repoint `current_version_id`.
 *
 * Two things this must never do:
 *
 *   - **Overwrite a version.** Regeneration always writes version N+1. The
 *     version somebody signed has to remain byte-identical to what they were
 *     shown, which is the whole reason the table exists.
 *
 *   - **Regenerate under a provider.** Once a document is out for signature,
 *     the signer is looking at a specific version; producing a new one behind
 *     them would mean the CRM and the counterparty disagree about what was
 *     agreed.
 */
class DocumentGenerator
{
    public function __construct(
        private readonly TemplateRenderer $renderer,
        private readonly VariableResolver $resolver,
        private readonly DocumentAssetService $assets,
    ) {
    }

    /**
     * @throws DocumentRenderException
     */
    public function generate(Document $document, ?User $actor = null): DocumentVersion
    {
        if ($document->isWithProvider()) {
            throw new DocumentRenderException(
                'Este documento já está em assinatura. Cancele-o antes de gerar uma nova versão.'
            );
        }

        if (DocumentStatus::isTerminal($document->status)) {
            throw new DocumentRenderException(
                'Documentos finalizados não podem ser gerados novamente.'
            );
        }

        $template = $document->template;

        if (!$template) {
            throw new DocumentRenderException('Este documento não tem um modelo associado.');
        }

        if (!$template->isUsable()) {
            throw new DocumentRenderException(
                'O modelo não está publicado. Peça ao time responsável para publicá-lo antes de usá-lo.'
            );
        }

        // Snapshot first: everything below describes *this* body, and the
        // template may be edited a minute from now.
        $sourceHtml = $template->body_html;
        $context    = $this->resolver->resolve($document);
        $rendered   = $this->renderer->render($sourceHtml, $context);

        // Assets become data URIs here, not in the stored body. The version's
        // `rendered_html` keeps the references — it is a record of what was
        // authored — while the PDF carries the bytes, so it stays readable
        // even after the asset is moved or deleted.
        $pdf = $this->pdfRenderer()->render($this->assets->inlineAssets($rendered), array_merge(
            $template->pdfOptions(),
            [
                'title'       => $document->title,
                'header_html' => $template->header_html
                    ? $this->assets->inlineAssets($this->renderer->render($template->header_html, $context))
                    : null,
                'footer_html' => $template->footer_html
                    ? $this->assets->inlineAssets($this->renderer->render($template->footer_html, $context))
                    : null,
            ]
        ));

        return DB::transaction(function () use ($document, $sourceHtml, $rendered, $context, $pdf, $actor) {
            $versionNumber = ((int) $document->versions()->max('version_number')) + 1;

            $path = sprintf(
                'documents/%d/%d/v%d-%s.pdf',
                $document->company_id,
                $document->id,
                $versionNumber,
                str_replace('/', '-', $document->number)
            );

            Storage::disk('local')->put($path, $pdf);

            $version = DocumentVersion::create([
                'company_id'     => $document->company_id,
                'document_id'    => $document->id,
                'version_number' => $versionNumber,
                'source_html'    => $sourceHtml,
                'rendered_html'  => $rendered,
                'variables'      => $context,
                'pdf_disk'       => 'local',
                'pdf_path'       => $path,
                'pdf_size_bytes' => strlen($pdf),
                'checksum'       => hash('sha256', $pdf),
                'generated_by'   => $actor?->id,
            ]);

            CompanyStorage::record(
                $document->company_id,
                $path,
                CompanyFile::CATEGORY_SIGNATURE_DOCUMENT,
                owner: $version,
                mime: 'application/pdf',
            );

            $document->forceFill([
                'current_version_id' => $version->id,
                // A draft that now has a rendered body is ready to move on;
                // any later status is left alone.
                'status' => $document->status === DocumentStatus::DRAFT
                    ? DocumentStatus::READY_TO_SEND
                    : $document->status,
            ])->save();

            event(new DocumentGenerated($document, $version, $actor));

            return $version;
        });
    }

    /**
     * Render a template without persisting anything — the editor's preview.
     *
     * Takes a body rather than a template id so an author can preview
     * unsaved edits.
     */
    public function preview(string $bodyHtml, Document $document): string
    {
        return $this->renderer->render($bodyHtml, $this->resolver->resolve($document));
    }

    private function pdfRenderer(): PdfRenderer
    {
        $driver = (string) config('documents.pdf.driver', 'dompdf');
        $class  = config("documents.pdf.drivers.{$driver}");

        if (!$class || !class_exists($class)) {
            throw new DocumentRenderException("Driver de PDF [{$driver}] não está registrado.");
        }

        return app($class);
    }
}
