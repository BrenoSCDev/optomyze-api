<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\Lead;
use App\Models\LeadCompany;
use App\Models\Sale;
use App\Services\Documents\DocumentAuthorizer;
use App\Services\Documents\DocumentGenerator;
use App\Services\Documents\DocumentHistory;
use App\Services\Documents\DocumentNumberGenerator;
use App\Services\Documents\Exceptions\DocumentRenderException;
use App\Services\Documents\Support\DocumentStatus;
use App\Services\Signatures\Exceptions\ProviderNotConfiguredException;
use App\Services\Signatures\Exceptions\SignatureProviderException;
use App\Services\Signatures\SignatureRequestService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Documents — the module's front door.
 *
 * Contracts are documents whose type sits in the `contract` category, so this
 * one controller serves contracts, proposals, quotes and NDAs. Nothing here
 * mentions a signature vendor: sending delegates to the signature module,
 * which delegates to whichever provider the company configured.
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentAuthorizer $authorizer,
        private readonly DocumentGenerator $generator,
        private readonly DocumentNumberGenerator $numbers,
        private readonly SignatureRequestService $signatures,
        private readonly DocumentHistory $history,
    ) {
    }

    public function index(Request $request)
    {
        $user = Auth::user();

        if (!$user?->company_id) {
            return response()->json(['message' => 'User does not belong to a company.'], 400);
        }

        $query = Document::with(['type', 'owner', 'lead', 'sale', 'currentVersion'])
            ->fromCompany($user->company_id);

        if (!$request->boolean('include_archived')) {
            $query->notArchived();
        }

        if ($status = $request->query('status')) {
            $query->whereIn('status', explode(',', $status));
        }

        if ($category = $request->query('category')) {
            $query->ofCategory($category);
        }

        if ($typeId = $request->query('document_type_id')) {
            $query->where('document_type_id', $typeId);
        }

        if ($leadId = $request->query('lead_id')) {
            $query->where('lead_id', $leadId);
        }

        if ($saleId = $request->query('sale_id')) {
            $query->where('sale_id', $saleId);
        }

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q
                ->where('title', 'like', "%{$search}%")
                ->orWhere('number', 'like', "%{$search}%"));
        }

        // The Kanban wants every card at once; the table view paginates.
        if ($request->boolean('board')) {
            return response()->json([
                'data'     => $query->orderByDesc('created_at')->limit(500)->get()
                    ->map(fn (Document $d) => $this->format($d)),
                'statuses' => DocumentStatus::BOARD,
            ]);
        }

        $documents = $query->orderByDesc('created_at')
            ->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'data' => collect($documents->items())->map(fn (Document $d) => $this->format($d)),
            'meta' => [
                'current_page' => $documents->currentPage(),
                'last_page'    => $documents->lastPage(),
                'per_page'     => $documents->perPage(),
                'total'        => $documents->total(),
            ],
        ]);
    }

    public function show(Request $request, int $id)
    {
        $document = $this->find($id);

        if ($document instanceof \Illuminate\Http\JsonResponse) {
            return $document;
        }

        return response()->json($this->format($document, detailed: true));
    }

    /**
     * Create a document. The number is allocated here and never changes —
     * it is quoted in correspondence from the moment the record exists.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        if (!$user?->company_id) {
            return response()->json(['message' => 'User does not belong to a company.'], 400);
        }

        try {
            $this->authorizer->authorize($this->authorizer->canCreate($user));
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        $validated = $request->validate([
            'document_type_id'     => ['required', 'integer'],
            'title'                => ['required', 'string', 'max:255'],
            'document_template_id' => ['nullable', 'integer'],
            'lead_id'              => ['nullable', 'integer'],
            'sale_id'              => ['nullable', 'integer'],
            'lead_company_id'      => ['nullable', 'integer'],
            'owner_id'             => ['nullable', 'integer'],
            'value'                => ['nullable', 'numeric', 'min:0'],
            'currency'             => ['nullable', 'string', 'size:3'],
            'start_date'           => ['nullable', 'date'],
            'end_date'             => ['nullable', 'date', 'after_or_equal:start_date'],
            'renewal_date'         => ['nullable', 'date'],
            'auto_renew'           => ['nullable', 'boolean'],
            'renewal_frequency'    => ['nullable', 'string', 'max:20'],
            'notice_period_days'   => ['nullable', 'integer', 'min:0', 'max:365'],
            'generate'             => ['nullable', 'boolean'],
        ]);

        $type = DocumentType::fromCompany($user->company_id)->find($validated['document_type_id']);

        if (!$type) {
            return response()->json(['message' => 'Tipo de documento não encontrado.'], 404);
        }

        // Every related record is re-checked against the caller's company —
        // an id in a request body is an assertion, not a fact.
        if ($error = $this->assertRelations($validated, $user->company_id)) {
            return $error;
        }

        $document = Document::create([
            'company_id'           => $user->company_id,
            'document_type_id'     => $type->id,
            'number'               => $this->numbers->generate($user->company_id, $type),
            'title'                => $validated['title'],
            'status'               => DocumentStatus::DRAFT,
            'lead_id'              => $validated['lead_id'] ?? null,
            'sale_id'              => $validated['sale_id'] ?? null,
            'lead_company_id'      => $validated['lead_company_id'] ?? null,
            'owner_id'             => $validated['owner_id'] ?? $user->id,
            'created_by'           => $user->id,
            'document_template_id' => $validated['document_template_id'] ?? $type->default_template_id,
            'value'                => $validated['value'] ?? null,
            'currency'             => $validated['currency'] ?? null,
            'start_date'           => $validated['start_date'] ?? null,
            'end_date'             => $validated['end_date'] ?? null,
            'renewal_date'         => $validated['renewal_date'] ?? null,
            'auto_renew'           => $validated['auto_renew'] ?? false,
            'renewal_frequency'    => $validated['renewal_frequency'] ?? null,
            'notice_period_days'   => $validated['notice_period_days'] ?? null,
        ]);

        if ($request->boolean('generate') && $document->document_template_id) {
            try {
                $this->generator->generate($document, $user);
            } catch (DocumentRenderException $e) {
                // The document exists and is fixable; the render is what failed.
                return response()->json([
                    'message'  => 'Documento criado, mas não foi possível gerar o conteúdo.',
                    'error'    => $e->getMessage(),
                    'data'     => $this->format($document->fresh(), detailed: true),
                ], 422);
            }
        }

        return response()->json([
            'message' => 'Documento criado.',
            'data'    => $this->format($document->fresh(['type', 'owner', 'currentVersion']), detailed: true),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $document = $this->find($id);

        if ($document instanceof \Illuminate\Http\JsonResponse) {
            return $document;
        }

        try {
            $this->authorizer->authorize($this->authorizer->canEdit(Auth::user(), $document));
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        if (!$document->isEditable()) {
            return response()->json([
                'message' => 'Este documento não pode mais ser editado.',
            ], 422);
        }

        $validated = $request->validate([
            'title'                => ['sometimes', 'string', 'max:255'],
            'document_template_id' => ['sometimes', 'nullable', 'integer'],
            'owner_id'             => ['sometimes', 'nullable', 'integer'],
            'value'                => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency'             => ['sometimes', 'nullable', 'string', 'size:3'],
            'start_date'           => ['sometimes', 'nullable', 'date'],
            'end_date'             => ['sometimes', 'nullable', 'date'],
            'renewal_date'         => ['sometimes', 'nullable', 'date'],
            'auto_renew'           => ['sometimes', 'boolean'],
            'renewal_frequency'    => ['sometimes', 'nullable', 'string', 'max:20'],
            'notice_period_days'   => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
        ]);

        if ($error = $this->assertRelations($validated, $document->company_id)) {
            return $error;
        }

        $document->update($validated);

        return response()->json([
            'message' => 'Documento atualizado.',
            'data'    => $this->format($document->fresh(['type', 'owner', 'currentVersion']), detailed: true),
        ]);
    }

    /** Render a new immutable version from the attached template. */
    public function generate(Request $request, int $id)
    {
        $document = $this->find($id);

        if ($document instanceof \Illuminate\Http\JsonResponse) {
            return $document;
        }

        try {
            $this->authorizer->authorize($this->authorizer->canEdit(Auth::user(), $document));

            $version = $this->generator->generate($document, Auth::user());
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (DocumentRenderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Versão gerada.',
            'version' => $version->toPublicArray(),
            'data'    => $this->format($document->fresh(['type', 'owner', 'currentVersion']), detailed: true),
        ]);
    }

    /**
     * Send the current version for signature.
     *
     * The signers arrive with the request rather than being stored on the
     * document, because who signs is a property of *this* sending — resending
     * a rejected contract often means sending it to somebody else.
     */
    public function send(Request $request, int $id)
    {
        $document = $this->find($id);

        if ($document instanceof \Illuminate\Http\JsonResponse) {
            return $document;
        }

        $validated = $request->validate([
            'signers'                   => ['required', 'array', 'min:1'],
            'signers.*.name'            => ['required', 'string', 'max:255'],
            'signers.*.email'           => ['nullable', 'email', 'max:255'],
            'signers.*.phone'           => ['nullable', 'string', 'max:30'],
            'signers.*.document_number' => ['nullable', 'string', 'max:40'],
            'signers.*.birthdate'       => ['nullable', 'date'],
            'signers.*.signing_group'   => ['nullable', 'integer', 'min:1', 'max:255'],
            'signers.*.refusable'       => ['nullable', 'boolean'],
            'signers.*.lead_id'         => ['nullable', 'integer'],
            'signers.*.auth_methods'    => ['nullable', 'array'],
            'signers.*.auth_methods.*'  => ['string', 'max:40'],
            'signers.*.communication'   => ['nullable', 'array'],
            'message'                   => ['nullable', 'string', 'max:5000'],
            'subject'                   => ['nullable', 'string', 'max:255'],
            'locale'                    => ['nullable', 'string', 'max:10'],
            'reminder_interval'         => ['nullable', 'integer'],
            'block_after_refusal'       => ['nullable', 'boolean'],
            'deadline_at'               => ['nullable', 'date', 'after:now'],
            'provider'                  => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $this->authorizer->authorize($this->authorizer->canSend(Auth::user(), $document));

            if (!$document->hasGeneratedVersion()) {
                return response()->json([
                    'message' => 'Gere o documento antes de enviá-lo para assinatura.',
                ], 422);
            }

            if ($document->isWithProvider()) {
                return response()->json([
                    'message' => 'Este documento já está em assinatura.',
                ], 422);
            }

            $signatureRequest = $this->signatures->createForDocument(
                $document,
                $document->currentVersion,
                $validated['signers'],
                $validated,
                Auth::user(),
            );

            $this->signatures->send($signatureRequest, Auth::user());
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (ProviderNotConfiguredException $e) {
            return response()->json([
                'message' => 'A integração de assinatura não está configurada.',
                'error'   => $e->getMessage(),
            ], 422);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Não foi possível enviar para assinatura.',
                'errors'  => $e->errors(),
            ], 422);
        }

        return response()->json([
            'message' => 'Documento enviado para assinatura.',
            'data'    => $this->format($document->fresh(['type', 'owner', 'currentVersion']), detailed: true),
        ]);
    }

    public function cancel(Request $request, int $id)
    {
        $document = $this->find($id);

        if ($document instanceof \Illuminate\Http\JsonResponse) {
            return $document;
        }

        try {
            $this->authorizer->authorize($this->authorizer->canCancel(Auth::user(), $document));

            if ($document->isTerminal()) {
                return response()->json([
                    'message' => 'Este documento já foi finalizado.',
                ], 422);
            }

            // Withdraw at the provider first: cancelling here while the
            // counterparty can still sign would put the CRM and reality out of
            // step in the one direction that matters.
            $signatureRequest = $document->signatureRequest;

            if ($signatureRequest && !$signatureRequest->isTerminal()) {
                $this->signatures->cancel($signatureRequest);
            }

            $document->forceFill([
                'status'       => DocumentStatus::CANCELLED,
                'cancelled_at' => now(),
            ])->save();
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (SignatureProviderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Documento cancelado.',
            'data'    => $this->format($document->fresh(['type', 'owner', 'currentVersion']), detailed: true),
        ]);
    }

    /**
     * Kanban drag.
     *
     * Only the columns a human owns are settable. Everything from
     * "waiting signature" onward is decided by the provider's webhooks, and
     * letting someone drag a card to "Signed" would put a lie on a legal
     * record.
     */
    public function updateStatus(Request $request, int $id)
    {
        $document = $this->find($id);

        if ($document instanceof \Illuminate\Http\JsonResponse) {
            return $document;
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', DocumentStatus::MANUALLY_SETTABLE)],
        ]);

        try {
            $this->authorizer->authorize($this->authorizer->canEdit(Auth::user(), $document));
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        if ($document->isWithProvider()) {
            return response()->json([
                'message' => 'Este documento está em assinatura e seu status é controlado pelo provedor.',
            ], 422);
        }

        $document->forceFill([
            'status'       => $validated['status'],
            'archived_at'  => $validated['status'] === DocumentStatus::ARCHIVED ? now() : null,
            'cancelled_at' => $validated['status'] === DocumentStatus::CANCELLED ? now() : $document->cancelled_at,
        ])->save();

        return response()->json([
            'message' => 'Status atualizado.',
            'data'    => $this->format($document->fresh(['type', 'owner', 'currentVersion']), detailed: true),
        ]);
    }

    public function versions(Request $request, int $id)
    {
        $document = $this->find($id);

        if ($document instanceof \Illuminate\Http\JsonResponse) {
            return $document;
        }

        return response()->json([
            'data' => $document->versions->map(fn (DocumentVersion $v) => $v->toPublicArray())->values(),
        ]);
    }

    /**
     * The document's full transaction history.
     *
     * `?technical=1` folds in the provider API traffic. Gated on the caller
     * being able to edit rather than merely view: the audit rows carry
     * endpoints, status codes and error text, which is operator detail, not
     * something to put in front of everyone with read access.
     */
    public function history(Request $request, int $id)
    {
        $document = $this->find($id);

        if ($document instanceof \Illuminate\Http\JsonResponse) {
            return $document;
        }

        $wantsTechnical  = $request->boolean('technical');
        $maySeeTechnical = $this->authorizer->canEdit(Auth::user(), $document);

        return response()->json([
            'data'              => $this->history->for($document, $wantsTechnical && $maySeeTechnical),
            'technical_included' => $wantsTechnical && $maySeeTechnical,
        ]);
    }

    /** The rendered HTML of one version, for on-screen preview. */
    public function versionPreview(Request $request, int $id, int $versionId)
    {
        $document = $this->find($id);

        if ($document instanceof \Illuminate\Http\JsonResponse) {
            return $document;
        }

        $version = $document->versions()->find($versionId);

        if (!$version) {
            return response()->json(['message' => 'Versão não encontrada.'], 404);
        }

        return response()->json([
            'version_number' => $version->version_number,
            'html'           => $version->rendered_html,
        ]);
    }

    /**
     * Download a version's PDF — the signed copy when one exists.
     */
    public function download(Request $request, int $id, ?int $versionId = null)
    {
        $document = $this->find($id);

        if ($document instanceof \Illuminate\Http\JsonResponse) {
            return $document;
        }

        $version = $versionId
            ? $document->versions()->find($versionId)
            : $document->currentVersion;

        if (!$version) {
            return response()->json(['message' => 'Versão não encontrada.'], 404);
        }

        // A completed signature produces a countersigned file from the
        // provider; that is the one anybody actually wants.
        $signed = $document->signatureRequest?->documents
            ->first(fn ($d) => $d->hasSignedCopy());

        $disk = $signed?->disk ?? $version->pdf_disk;
        $path = $signed?->signed_path ?? $version->pdf_path;
        $name = $signed
            ? "assinado-{$document->number}.pdf"
            : "{$document->number}-v{$version->version_number}.pdf";

        if (!$path || !Storage::disk($disk)->exists($path)) {
            return response()->json(['message' => 'Arquivo indisponível.'], 404);
        }

        return Storage::disk($disk)->download($path, $name);
    }

    public function destroy(Request $request, int $id)
    {
        $document = $this->find($id);

        if ($document instanceof \Illuminate\Http\JsonResponse) {
            return $document;
        }

        try {
            $this->authorizer->authorize(
                $this->authorizer->canDelete(Auth::user(), $document),
                'Apenas rascunhos podem ser excluídos, e somente pelo responsável ou por um gestor.'
            );
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        // Versions and their files go with it via cascade + storage cleanup.
        foreach ($document->versions as $version) {
            if ($version->pdf_path) {
                Storage::disk($version->pdf_disk)->delete($version->pdf_path);
                \App\Services\Storage\CompanyStorage::forget($version->pdf_path, $version->pdf_disk);
            }
        }

        $document->delete();

        return response()->json(['message' => 'Documento excluído.']);
    }

    /* ============================
     | Helpers
     ============================ */

    private function find(int $id): Document|\Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        if (!$user?->company_id) {
            return response()->json(['message' => 'User does not belong to a company.'], 400);
        }

        $document = Document::with([
            'type', 'owner', 'creator', 'lead', 'sale', 'leadCompany',
            'template', 'currentVersion', 'versions',
            'signatureRequest.signers', 'signatureRequest.documents',
        ])->fromCompany($user->company_id)->find($id);

        if (!$document) {
            return response()->json(['message' => 'Documento não encontrado.'], 404);
        }

        if (!$this->authorizer->canView($user, $document)) {
            return response()->json(['message' => 'Documento não encontrado.'], 404);
        }

        return $document;
    }

    /**
     * A related id in a request body must belong to the caller's company.
     * Returning 404 rather than 403 so a probe cannot enumerate other
     * tenants' record ids.
     */
    private function assertRelations(array $data, int $companyId): ?\Illuminate\Http\JsonResponse
    {
        $checks = [
            'lead_id'              => Lead::class,
            'sale_id'              => Sale::class,
            'lead_company_id'      => LeadCompany::class,
            'document_template_id' => DocumentTemplate::class,
        ];

        foreach ($checks as $field => $model) {
            if (empty($data[$field])) {
                continue;
            }

            $exists = $model::where('company_id', $companyId)->whereKey($data[$field])->exists();

            if (!$exists) {
                return response()->json([
                    'message' => 'Registro relacionado não encontrado.',
                    'field'   => $field,
                ], 404);
            }
        }

        return null;
    }

    private function format(Document $document, bool $detailed = false): array
    {
        // Shared with the websocket broadcast — see Document::toCardArray().
        $payload = $document->toCardArray();

        if (!$detailed) {
            return $payload;
        }

        $signatureRequest = $document->signatureRequest;

        return array_merge($payload, [
            'document_template_id' => $document->document_template_id,
            'template_name'        => $document->template?->name,
            'created_by'           => $document->creator?->name,
            'renewal_frequency'    => $document->renewal_frequency,
            'notice_period_days'   => $document->notice_period_days,
            'approval_status'      => $document->approval_status,
            'versions'             => $document->versions->map(fn ($v) => $v->toPublicArray())->values(),
            'signature'            => $signatureRequest ? [
                'id'       => $signatureRequest->id,
                'status'   => $signatureRequest->status,
                'provider' => $signatureRequest->provider,
                'sent_at'  => $signatureRequest->sent_at,
                'last_error' => $signatureRequest->last_error,
                'signers'  => $signatureRequest->signers->map(fn ($s) => $s->toPublicArray())->values(),
                'documents' => $signatureRequest->documents->map(fn ($d) => $d->toPublicArray())->values(),
            ] : null,
        ]);
    }
}
