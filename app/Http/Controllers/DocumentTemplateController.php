<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateRevision;
use App\Services\Documents\DocumentAssetService;
use App\Services\Documents\DocumentAuthorizer;
use App\Services\Documents\DocumentGenerator;
use App\Services\Documents\Exceptions\DocumentRenderException;
use App\Services\Documents\TemplateExporter;
use App\Services\Documents\TemplateRenderer;
use App\Services\Documents\VariableRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Document templates — legal's instrument.
 *
 * Writing and publishing is restricted; using a published template is not.
 * The split matters: a contract body is the company's legal exposure, and the
 * person who closes deals should not be the person who edits the indemnity
 * clause.
 */
class DocumentTemplateController extends Controller
{
    public function __construct(
        private readonly DocumentAuthorizer $authorizer,
        private readonly TemplateRenderer $renderer,
        private readonly VariableRegistry $registry,
        private readonly DocumentGenerator $generator,
        private readonly DocumentAssetService $assets,
        private readonly TemplateExporter $exporter,
    ) {
    }

    public function index(Request $request)
    {
        $user = Auth::user();

        if (!$user?->company_id) {
            return response()->json(['message' => 'User does not belong to a company.'], 400);
        }

        $query = DocumentTemplate::with('type')->fromCompany($user->company_id);

        // Anyone composing a document only ever sees usable templates.
        if ($request->boolean('published_only')) {
            $query->published();
        }

        if ($typeId = $request->query('document_type_id')) {
            $query->where('document_type_id', $typeId);
        }

        return response()->json([
            'data' => $query->orderBy('name')->get()
                ->map(fn (DocumentTemplate $t) => $t->toPublicArray())
                ->values(),
        ]);
    }

    public function show(Request $request, int $id)
    {
        $template = $this->find($id);

        if ($template instanceof \Illuminate\Http\JsonResponse) {
            return $template;
        }

        return response()->json($template->toPublicArray(withBody: true));
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        if (!$user?->company_id) {
            return response()->json(['message' => 'User does not belong to a company.'], 400);
        }

        if (!$this->authorizer->canManageTemplates($user)) {
            return response()->json(['message' => 'Apenas gestores podem criar modelos.'], 403);
        }

        $validated = $this->validatePayload($request);

        $template = DocumentTemplate::create(array_merge($validated, [
            'company_id'       => $user->company_id,
            'created_by'       => $user->id,
            'variables_schema' => $this->describeVariables($validated['body_html']),
        ]));

        return response()->json([
            'message' => 'Modelo criado.',
            'data'    => $template->toPublicArray(withBody: true),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $template = $this->find($id);

        if ($template instanceof \Illuminate\Http\JsonResponse) {
            return $template;
        }

        if (!$this->authorizer->canManageTemplates(Auth::user())) {
            return response()->json(['message' => 'Apenas gestores podem editar modelos.'], 403);
        }

        // Refuse to overwrite a body written by an editor this build does not
        // understand. Saving would silently drop the nodes we have no schema
        // for, and silently dropping a clause is this module's worst failure.
        if ($template->isFromNewerEditor() && $request->has('body_doc') === false) {
            return response()->json([
                'message' => 'Este modelo foi editado por uma versão mais recente do editor. '
                    . 'Atualize a página antes de continuar.',
            ], 409);
        }

        $validated = $this->validatePayload($request, partial: true);

        if (isset($validated['body_html'])) {
            $validated['variables_schema'] = $this->describeVariables($validated['body_html']);
        }

        // Snapshot the body as it was, before the write. Cheap, and the only
        // thing standing between an author and an unrecoverable bad rewrite.
        $this->captureRevision(
            $template,
            $request->boolean('autosave')
                ? DocumentTemplateRevision::TRIGGER_AUTOSAVE
                : DocumentTemplateRevision::TRIGGER_MANUAL
        );

        $template->update($validated);

        return response()->json([
            'message' => 'Modelo atualizado.',
            'data'    => $template->fresh()->toPublicArray(withBody: true),
        ]);
    }

    /** The revision list. Bodies are omitted — this is an index of labels. */
    public function revisions(Request $request, int $id)
    {
        $template = $this->find($id);

        if ($template instanceof \Illuminate\Http\JsonResponse) {
            return $template;
        }

        return response()->json([
            'data' => $template->revisions()->with('author:id,name')->limit(100)->get()
                ->map(fn (DocumentTemplateRevision $r) => $r->toPublicArray())
                ->values(),
        ]);
    }

    /**
     * Put a past revision back.
     *
     * The current body is captured first, so restoring is itself undoable —
     * the failure mode otherwise is restoring the wrong revision and losing
     * the thing you were trying to get back to.
     */
    public function restoreRevision(Request $request, int $id, int $revisionId)
    {
        $template = $this->find($id);

        if ($template instanceof \Illuminate\Http\JsonResponse) {
            return $template;
        }

        if (!$this->authorizer->canManageTemplates(Auth::user())) {
            return response()->json(['message' => 'Apenas gestores podem restaurar modelos.'], 403);
        }

        $revision = $template->revisions()->find($revisionId);

        if (!$revision) {
            return response()->json(['message' => 'Revisão não encontrada.'], 404);
        }

        $this->captureRevision($template, DocumentTemplateRevision::TRIGGER_RESTORE);

        $template->update([
            'body_doc'         => $revision->body_doc,
            'body_html'        => $revision->body_html,
            'header_doc'       => $revision->header_doc,
            'header_html'      => $revision->header_html,
            'footer_doc'       => $revision->footer_doc,
            'footer_html'      => $revision->footer_html,
            'page_settings'    => $revision->page_settings,
            'variables_schema' => $this->describeVariables($revision->body_html ?? ''),
        ]);

        return response()->json([
            'message' => "Revisão {$revision->revision_number} restaurada.",
            'data'    => $template->fresh()->toPublicArray(withBody: true),
        ]);
    }

    /**
     * Publishing is a separate action from saving, so an author can work on a
     * body without it becoming usable mid-edit. The body is rendered against
     * an empty context first — a template that cannot parse must never reach
     * the people who send contracts.
     */
    public function publish(Request $request, int $id)
    {
        $template = $this->find($id);

        if ($template instanceof \Illuminate\Http\JsonResponse) {
            return $template;
        }

        if (!$this->authorizer->canManageTemplates(Auth::user())) {
            return response()->json(['message' => 'Apenas gestores podem publicar modelos.'], 403);
        }

        $publish = $request->boolean('published', true);

        if ($publish) {
            try {
                $this->renderer->render($template->body_html, []);
            } catch (DocumentRenderException $e) {
                return response()->json([
                    'message' => 'O modelo tem um erro e não pode ser publicado.',
                    'error'   => $e->getMessage(),
                ], 422);
            }
        }

        $template->update([
            'is_published' => $publish,
            'published_at' => $publish ? now() : null,
        ]);

        return response()->json([
            'message' => $publish ? 'Modelo publicado.' : 'Publicação removida.',
            'data'    => $template->fresh()->toPublicArray(),
        ]);
    }

    /**
     * Render a body without saving anything — the editor's live preview.
     *
     * Accepts a raw body so unsaved edits can be previewed, and an optional
     * document to resolve against so the author sees real values rather than
     * placeholder text.
     */
    public function preview(Request $request, int $id)
    {
        $template = $this->find($id);

        if ($template instanceof \Illuminate\Http\JsonResponse) {
            return $template;
        }

        $validated = $request->validate([
            'body_html'   => ['nullable', 'string'],
            'document_id' => ['nullable', 'integer'],
            // "Preview with CRM data": resolve against a real lead or deal
            // without needing a document to exist first. This is how an author
            // checks that variables, tables and page breaks behave *before*
            // committing a contract to a customer.
            'lead_id'     => ['nullable', 'integer'],
            'sale_id'     => ['nullable', 'integer'],
        ]);

        $body = $validated['body_html'] ?? $template->body_html;

        try {
            $document = $this->previewSubject($validated, $template);

            if ($document instanceof \Illuminate\Http\JsonResponse) {
                return $document;
            }

            $html = $document
                ? $this->generator->preview($body, $document)
                // No subject: parse-check only, so the author still sees layout.
                : $this->renderer->render($body, []);

            return response()->json([
                'html'          => $this->assets->inlineAssets($html),
                'page_settings' => $template->pageSettings(),
                'header_html'   => $template->header_html
                    ? $this->assets->inlineAssets($this->renderer->render($template->header_html, []))
                    : null,
                'footer_html'   => $template->footer_html
                    ? $this->assets->inlineAssets($this->renderer->render($template->footer_html, []))
                    : null,
                'resolved_against' => $document?->only(['id', 'number', 'title']),
            ]);
        } catch (DocumentRenderException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'line'    => $e->templateLine,
            ], 422);
        }
    }

    /**
     * The document a preview resolves variables against.
     *
     * An existing document is used as-is. A lead or deal instead produces an
     * *unsaved* Document — never persisted, never numbered — purely as the
     * subject VariableResolver needs. Creating a real row to preview a
     * template would litter the contract register with drafts nobody asked
     * for, and would burn contract numbers, which are quoted in
     * correspondence and must not have gaps.
     */
    private function previewSubject(array $validated, DocumentTemplate $template): Document|\Illuminate\Http\JsonResponse|null
    {
        $companyId = Auth::user()->company_id;

        if (!empty($validated['document_id'])) {
            $document = Document::fromCompany($companyId)->find($validated['document_id']);

            return $document ?: response()->json(['message' => 'Documento não encontrado.'], 404);
        }

        $leadId = $validated['lead_id'] ?? null;
        $saleId = $validated['sale_id'] ?? null;

        if (!$leadId && !$saleId) {
            return null;
        }

        if ($saleId) {
            $sale = \App\Models\Sale::where('company_id', $companyId)->find($saleId);

            if (!$sale) {
                return response()->json(['message' => 'Negócio não encontrado.'], 404);
            }

            // A deal implies its lead, so the author sees contact variables
            // resolve too rather than a page of blanks.
            $leadId = $leadId ?: $sale->lead_id;
        }

        if ($leadId) {
            $lead = \App\Models\Lead::where('company_id', $companyId)->find($leadId);

            if (!$lead) {
                return response()->json(['message' => 'Contato não encontrado.'], 404);
            }
        }

        $document = new Document([
            'company_id'       => $companyId,
            'document_type_id' => $template->document_type_id,
            'title'            => $template->name,
            'lead_id'          => $leadId,
            'sale_id'          => $saleId,
            'owner_id'         => Auth::id(),
        ]);

        // A plausible number so {{document.number}} does not preview as blank,
        // marked so nobody mistakes a preview for a real contract.
        $document->number = 'PREVIEW';
        $document->exists = false;

        return $document;
    }

    /**
     * Download the template as a file.
     *
     * `format=pdf|docx`, and optionally a lead or deal to resolve variables
     * against. Without one the tokens stay visible, which is usually what an
     * author reviewing wording actually wants.
     *
     * Nothing is persisted — this produces no document, no contract number and
     * no version. It is a copy of the template, not a contract.
     */
    public function download(Request $request, int $id)
    {
        $template = $this->find($id);

        if ($template instanceof \Illuminate\Http\JsonResponse) {
            return $template;
        }

        $validated = $request->validate([
            'format'  => ['nullable', 'string', 'in:pdf,docx'],
            'lead_id' => ['nullable', 'integer'],
            'sale_id' => ['nullable', 'integer'],
        ]);

        $format = $validated['format'] ?? 'pdf';

        // Tenancy: `find()` already scoped the template, but the records used
        // to resolve variables arrive as ids in a query string.
        $companyId = Auth::user()->company_id;
        $leadId    = null;
        $saleId    = null;

        if (!empty($validated['lead_id'])) {
            $leadId = \App\Models\Lead::where('company_id', $companyId)
                ->whereKey($validated['lead_id'])->value('id');
        }

        if (!empty($validated['sale_id'])) {
            $saleId = \App\Models\Sale::where('company_id', $companyId)
                ->whereKey($validated['sale_id'])->value('id');
        }

        try {
            $context = $this->exporter->contextFor($template, $leadId, $saleId);

            [$bytes, $mime, $extension] = $format === 'docx'
                ? [
                    $this->exporter->toWord($template, $context),
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'docx',
                ]
                : [$this->exporter->toPdf($template, $context), 'application/pdf', 'pdf'];
        } catch (DocumentRenderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $filename = Str::slug($template->name) ?: 'modelo';

        return response($bytes, 200, [
            'Content-Type'        => $mime,
            'Content-Disposition' => "attachment; filename={$filename}.{$extension}",
            'Content-Length'      => strlen($bytes),
        ]);
    }

    /**
     * Upload an image for use in a template.
     *
     * Stored through CompanyStorage and referenced by URL — never base64'd
     * into the document body, which would repeat the same megabytes across
     * every autosave revision and every generated contract. See
     * DocumentAssetService for the full reasoning.
     */
    public function uploadAsset(Request $request)
    {
        if (!$this->authorizer->canManageTemplates(Auth::user())) {
            return response()->json(['message' => 'Apenas gestores podem enviar imagens.'], 403);
        }

        $request->validate([
            'file'        => [
                'required',
                'file',
                'mimetypes:' . implode(',', DocumentAssetService::ALLOWED_MIMES),
                'max:' . (DocumentAssetService::MAX_BYTES / 1024),
            ],
            'template_id' => ['nullable', 'integer'],
        ]);

        $template = null;

        if ($request->filled('template_id')) {
            $found = DocumentTemplate::fromCompany(Auth::user()->company_id)
                ->find($request->integer('template_id'));

            $template = $found ?: null;
        }

        return response()->json([
            'data' => $this->assets->store($request->file('file'), Auth::user()->company_id, $template),
        ], 201);
    }

    /**
     * Serve a stored asset.
     *
     * Signed rather than public: these are letterheads, signature images and
     * stamps. The signature also carries the tenant check — the path is
     * confined to the company's own asset prefix before anything is read.
     */
    public function showAsset(Request $request, string $path)
    {
        $decoded = base64_decode($path, true);

        if ($decoded === false
            || str_contains($decoded, '..')
            || !preg_match('#^documents/(\d+)/assets/#', $decoded, $matches)) {
            abort(404);
        }

        $disk = Storage::disk('local');

        if (!$disk->exists($decoded)) {
            abort(404);
        }

        return response($disk->get($decoded), 200, [
            'Content-Type'  => $disk->mimeType($decoded) ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $template = $this->find($id);

        if ($template instanceof \Illuminate\Http\JsonResponse) {
            return $template;
        }

        if (!$this->authorizer->canManageTemplates(Auth::user())) {
            return response()->json(['message' => 'Apenas gestores podem excluir modelos.'], 403);
        }

        // Documents keep a snapshot of the body they used, so deleting a
        // template cannot alter history — but the link is nulled rather than
        // cascading the documents away.
        if ($template->documents()->exists()) {
            $template->update(['is_active' => false, 'is_published' => false]);

            return response()->json([
                'message' => 'Modelo desativado (há documentos que o utilizaram).',
            ]);
        }

        $template->delete();

        return response()->json(['message' => 'Modelo excluído.']);
    }

    /* ============================
     | Helpers
     ============================ */

    private function validatePayload(Request $request, bool $partial = false): array
    {
        $rule = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name'             => [$rule, 'string', 'max:255'],
            'body_html'        => [$rule, 'string', 'max:1048576'],
            'document_type_id' => ['nullable', 'integer'],
            'description'      => ['nullable', 'string', 'max:1000'],
            'header_html'      => ['nullable', 'string', 'max:20000'],
            'footer_html'      => ['nullable', 'string', 'max:20000'],
            'is_active'        => ['nullable', 'boolean'],

            /*
             * The structured model from the visual editor.
             *
             * Not validated node-by-node: the schema lives in the editor, and
             * a server-side mirror of it would be a second definition to keep
             * in step. What is enforced is that it is an array and that the
             * paired HTML is present, since the HTML is what actually renders.
             */
            'body_doc'           => ['nullable', 'array'],
            'header_doc'         => ['nullable', 'array'],
            'footer_doc'         => ['nullable', 'array'],
            'doc_schema_version' => ['nullable', 'integer', 'min:1'],

            'page_settings'                     => ['nullable', 'array'],
            'page_settings.paper'               => ['nullable', 'string', 'in:a4,letter,legal,a3,a5'],
            'page_settings.orientation'         => ['nullable', 'string', 'in:portrait,landscape'],
            // Millimetres. Capped at 100 because a margin larger than that on
            // A4 leaves a column too narrow to render a contract in.
            'page_settings.margins.top'         => ['nullable', 'numeric', 'min:0', 'max:100'],
            'page_settings.margins.right'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            'page_settings.margins.bottom'      => ['nullable', 'numeric', 'min:0', 'max:100'],
            'page_settings.margins.left'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'page_settings.header_offset'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            'page_settings.footer_offset'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            'page_settings.font_family'         => ['nullable', 'string', 'max:60'],
            'page_settings.font_size'           => ['nullable', 'numeric', 'min:6', 'max:32'],
            'page_settings.line_height'         => ['nullable', 'numeric', 'min:1', 'max:4'],
            'page_settings.paragraph_spacing'   => ['nullable', 'numeric', 'min:0', 'max:60'],
        ]);
    }

    /**
     * Snapshot the template, never at the cost of the save.
     *
     * A revision is a convenience for the author; failing to write one must
     * not fail the edit they actually asked for.
     */
    private function captureRevision(DocumentTemplate $template, string $trigger): void
    {
        try {
            DocumentTemplateRevision::capture($template, $trigger, Auth::id());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Could not capture a template revision', [
                'template_id' => $template->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record which variables a body uses and which CRM records it therefore
     * needs, so the UI can warn before a document is composed against the
     * wrong kind of record.
     */
    private function describeVariables(string $body): array
    {
        $referenced = $this->renderer->referencedVariables($body);

        return [
            'referenced'   => $referenced,
            'requires'     => $this->registry->requirementsFor($referenced),
        ];
    }

    private function find(int $id): DocumentTemplate|\Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        if (!$user?->company_id) {
            return response()->json(['message' => 'User does not belong to a company.'], 400);
        }

        $template = DocumentTemplate::with('type')
            ->fromCompany($user->company_id)
            ->find($id);

        if (!$template) {
            return response()->json(['message' => 'Modelo não encontrado.'], 404);
        }

        return $template;
    }
}
