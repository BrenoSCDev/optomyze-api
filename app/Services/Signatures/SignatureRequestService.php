<?php

namespace App\Services\Signatures;

use App\Events\DocumentSent;
use App\Jobs\SendSignatureRequest;
use App\Models\CompanyFile;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\SignatureDocument;
use App\Models\SignatureProviderAccount;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Models\User;
use App\Services\Documents\Support\DocumentStatus;
use App\Services\Signatures\Data\DownloadedFile;
use App\Services\Signatures\Exceptions\ProviderNotConfiguredException;
use App\Services\Signatures\Support\SignatureStatus;
use App\Services\Signatures\Support\SignerStatus;
use App\Services\Storage\CompanyStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The signature stage of a document's life.
 *
 * Signatures are collected *for* a document, never standalone: the document is
 * the business record, the signature request is how a specific rendered
 * version of it gets signed. That is why every method here starts from a
 * Document, and why nothing in this class knows which vendor is involved —
 * drivers are reached only through SignatureProviderRegistry.
 */
class SignatureRequestService
{
    public function __construct(
        private readonly SignatureProviderRegistry $registry,
    ) {
    }

    /**
     * The account a company sends through.
     *
     * @throws ProviderNotConfiguredException
     */
    public function accountFor(int $companyId, ?string $provider = null): SignatureProviderAccount
    {
        $provider ??= $this->registry->defaultKey();

        $account = SignatureProviderAccount::fromCompany($companyId)
            ->forProvider($provider)
            ->first();

        if (!$account) {
            throw ProviderNotConfiguredException::missingCredentials($provider);
        }

        if (!$account->is_active) {
            throw ProviderNotConfiguredException::inactive($provider);
        }

        if (!$account->hasCredentials()) {
            throw ProviderNotConfiguredException::missingCredentials($provider);
        }

        return $account;
    }

    /**
     * Prepare a signature request for a specific rendered version.
     *
     * Pinning the version is the point: the signer sees exactly these bytes,
     * and regenerating the document afterwards cannot quietly change what was
     * agreed. The PDF is taken from the version rather than re-uploaded, so
     * there is one file and one checksum, not two that can drift.
     *
     * @param array<int, array<string, mixed>> $signers
     * @param array<string, mixed> $options
     */
    public function createForDocument(
        Document $document,
        DocumentVersion $version,
        array $signers,
        array $options = [],
        ?User $actor = null,
    ): SignatureRequest {
        $account = $this->accountFor($document->company_id, $options['provider'] ?? null);

        if (!$version->hasPdf()) {
            throw ValidationException::withMessages([
                'document' => ['Esta versão do documento não tem um PDF gerado.'],
            ]);
        }

        return DB::transaction(function () use ($document, $version, $signers, $options, $account, $actor) {
            $request = SignatureRequest::create([
                'company_id'            => $document->company_id,
                'document_id'           => $document->id,
                'document_version_id'   => $version->id,
                'signature_provider_id' => $account->id,
                'provider'              => $account->provider,
                'user_id'               => $actor?->id,
                'title'                 => $document->title,
                'subject'               => $options['subject'] ?? null,
                'message'               => $options['message'] ?? null,
                'status'                => SignatureStatus::DRAFT,
                'locale'                => $options['locale'] ?? 'pt-BR',
                'auto_close'            => $options['auto_close'] ?? true,
                'reminder_interval'     => $options['reminder_interval'] ?? 3,
                'block_after_refusal'   => $options['block_after_refusal'] ?? false,
                'deadline_at'           => $options['deadline_at'] ?? null,
            ]);

            $this->attachVersionPdf($request, $version, $document);

            foreach ($signers as $signer) {
                $this->addSigner($request, $signer);
            }

            return $request->fresh(['documents', 'signers']);
        });
    }

    /**
     * Point the signature request at the version's stored PDF.
     *
     * The file is referenced, not copied: `signature_documents.source_path`
     * carries the same path the version wrote, so the bytes exist once on disk
     * and are accounted once against the company's plan.
     */
    private function attachVersionPdf(
        SignatureRequest $request,
        DocumentVersion $version,
        Document $document
    ): SignatureDocument {
        return SignatureDocument::create([
            'company_id'           => $request->company_id,
            'signature_request_id' => $request->id,
            'filename'             => $this->pdfFilename($document, $version),
            'mime'                 => 'application/pdf',
            'size_bytes'           => (int) $version->pdf_size_bytes,
            'disk'                 => $version->pdf_disk,
            'source_path'          => $version->pdf_path,
            'source_checksum'      => $version->checksum ?? hash('sha256', $version->pdf_path),
            'position'             => 1,
        ]);
    }

    private function pdfFilename(Document $document, DocumentVersion $version): string
    {
        $slug = preg_replace('/[^A-Za-z0-9\-_]+/', '-', $document->number ?: 'documento');

        return "{$slug}-v{$version->version_number}.pdf";
    }

    /** @param array<string, mixed> $attributes */
    public function addSigner(SignatureRequest $request, array $attributes): SignatureSigner
    {
        return SignatureSigner::create([
            'company_id'           => $request->company_id,
            'signature_request_id' => $request->id,
            'lead_id'              => $attributes['lead_id'] ?? null,
            'user_id'              => $attributes['user_id'] ?? null,
            'name'                 => $attributes['name'],
            'email'                => $attributes['email'] ?? null,
            'phone'                => $attributes['phone'] ?? null,
            'document_number'      => $attributes['document_number'] ?? null,
            'birthdate'            => $attributes['birthdate'] ?? null,
            'signing_group'        => $attributes['signing_group'] ?? 1,
            'refusable'            => $attributes['refusable'] ?? true,
            'auth_methods'         => $attributes['auth_methods'] ?? ['email'],
            'communication'        => $attributes['communication'] ?? null,
        ]);
    }

    public function removeSigner(SignatureSigner $signer): void
    {
        $signer->delete();
    }

    /**
     * Hand the request to the vendor.
     *
     * Validation runs against the driver first, so a bad payload never costs
     * an API call. The send itself is queued: several vendor calls against a
     * per-account rate limit have no business inside a web request.
     *
     * @throws ValidationException
     */
    public function send(SignatureRequest $request, ?User $actor = null): SignatureRequest
    {
        if (!$request->isEditable()) {
            throw ValidationException::withMessages([
                'status' => ['Esta solicitação já foi enviada.'],
            ]);
        }

        $request->loadMissing(['documents', 'signers', 'providerAccount', 'document']);

        $account = $request->providerAccount;

        if (!$account || !$account->isUsable()) {
            throw ValidationException::withMessages([
                'provider' => ['A integração de assinatura não está conectada. Configure-a em Integrações.'],
            ]);
        }

        $errors = $this->registry->get($request->provider)->validateDraft($request);

        if ($errors !== []) {
            throw ValidationException::withMessages(['signature' => $errors]);
        }

        $request->forceFill([
            'status'     => SignatureStatus::QUEUED,
            'queued_at'  => now(),
            'last_error' => null,
        ])->save();

        // The document leaves the company's hands the moment the send is
        // accepted, not when the vendor confirms — the board should not show a
        // contract as still editable while a job is mid-flight.
        $request->document?->forceFill([
            'status'  => DocumentStatus::WAITING_SIGNATURE,
            'sent_at' => now(),
        ])->save();

        SendSignatureRequest::dispatch($request->id);

        if ($request->document) {
            event(new DocumentSent($request->document, $actor));
        }

        return $request;
    }

    /** Called by the send job once the vendor has accepted everything. */
    public function markSent(SignatureRequest $request): void
    {
        $request->forceFill([
            'status'     => SignatureStatus::SENT,
            'sent_at'    => now(),
            'last_error' => null,
        ])->save();

        foreach ($request->signers as $signer) {
            $signer->advanceTo(SignerStatus::NOTIFIED, ['notified_at' => now()]);
        }
    }

    /**
     * A send that failed permanently puts the document back where the user can
     * act on it — leaving it at "waiting signature" would be a lie.
     */
    public function markSendFailed(SignatureRequest $request): void
    {
        $request->document?->forceFill([
            'status'  => DocumentStatus::READY_TO_SEND,
            'sent_at' => null,
        ])->save();
    }

    public function cancel(SignatureRequest $request): SignatureRequest
    {
        if ($request->isTerminal()) {
            throw ValidationException::withMessages([
                'status' => ['Esta solicitação já foi finalizada e não pode ser cancelada.'],
            ]);
        }

        $request->loadMissing('providerAccount');

        if (!empty($request->provider_request_id)) {
            $this->registry->get($request->provider)->cancelRequest($request);
        }

        $request->forceFill([
            'status'      => SignatureStatus::CANCELED,
            'canceled_at' => now(),
        ])->save();

        return $request;
    }

    /** Resend the invitation, to everyone or to one signer. */
    public function resend(SignatureRequest $request, ?SignatureSigner $signer = null): void
    {
        $request->loadMissing('providerAccount');

        $provider = $this->registry->get($request->provider);

        if (!$provider->capabilities()->supportsResend) {
            throw ValidationException::withMessages([
                'provider' => ['Este provedor não permite reenviar convites de assinatura.'],
            ]);
        }

        $provider->notify($request, $signer);

        if ($signer) {
            $signer->advanceTo(SignerStatus::NOTIFIED, ['notified_at' => now()]);
            return;
        }

        foreach ($request->signers as $each) {
            $each->advanceTo(SignerStatus::NOTIFIED, ['notified_at' => now()]);
        }
    }

    /**
     * Persist a signed file fetched from the vendor.
     *
     * Kept on our own disk and counted against the company's plan storage,
     * because a vendor's download link is undocumented as to expiry and a
     * signed contract has to stay retrievable for years.
     */
    public function storeSignedDocument(SignatureDocument $document, DownloadedFile $file): void
    {
        $path = "documents/{$document->company_id}/signed/"
            . $document->id . '-' . $file->filename;

        Storage::disk($document->disk)->put($path, $file->contents);

        $document->forceFill([
            'signed_path'       => $path,
            'signed_size_bytes' => $file->sizeBytes(),
            'download_status'   => SignatureDocument::DOWNLOAD_STORED,
            'download_error'    => null,
            'downloaded_at'     => now(),
        ])->save();

        CompanyStorage::record(
            $document->company_id,
            $path,
            CompanyFile::CATEGORY_SIGNATURE_DOCUMENT,
            $document->disk,
            owner: $document,
            mime: $file->mimeType,
        );
    }
}
