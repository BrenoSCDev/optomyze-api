<?php

namespace App\Services\Signatures\Providers\Clicksign;

use App\Models\SignatureDocument;
use App\Models\SignatureProviderAccount;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Services\Signatures\Audit\SignatureAuditLogger;
use App\Services\Signatures\Contracts\SignatureProvider;
use App\Services\Signatures\Data\ConnectionResult;
use App\Services\Signatures\Data\DownloadedFile;
use App\Services\Signatures\Data\NormalizedEvent;
use App\Services\Signatures\Data\ProviderCapabilities;
use App\Services\Signatures\Data\RemoteRequestState;
use App\Services\Signatures\Data\WebhookRegistration;
use App\Services\Signatures\Exceptions\SignatureProviderException;
use App\Services\Signatures\Support\SignatureStatus;
use Carbon\Carbon;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * CLICKSIGN — API 3.0 ("Envelope")
 *
 * The first implementation of SignatureProvider. Everything Clicksign-shaped
 * lives here and in ClicksignClient/ClicksignEventMap; nothing above this
 * layer knows the vendor exists.
 *
 * The send flow is Clicksign's documented six steps
 * (https://developers.clicksign.com/docs/veja-como-funciona-na-prática):
 *
 *   1. POST /envelopes                                  create the container
 *   2. POST /envelopes/{id}/documents                    upload the file(s)
 *   3. POST /envelopes/{id}/signers                      register the people
 *   4. POST /envelopes/{id}/requirements                 who signs what, how
 *   5. PATCH /envelopes/{id}  {status: running}          activate
 *   6. POST /envelopes/{id}/notifications                actually invite them
 *
 * Two of those are easy to get wrong and worth stating plainly:
 *
 *   - Step 4 needs TWO requirements per (document, signer) pair — a
 *     qualification (`agree`/`sign`) and at least one authentication
 *     (`provide_evidence`). Without both, activation succeeds and nobody is
 *     ever asked to sign.
 *
 *   - Step 6 is not implied by step 5. Activating an envelope emails no one.
 *
 * The whole flow is **resumable**: it runs inside a retrying job, and every
 * provider id is persisted the moment Clicksign issues it. A retry after a
 * timeout picks up where it stopped instead of creating a second envelope and
 * billing the customer twice.
 */
class ClicksignProvider implements SignatureProvider
{
    public const KEY = 'clicksign';

    public function __construct(private readonly SignatureAuditLogger $audit)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return (string) config('signatures.providers.clicksign.label', 'Clicksign');
    }

    /**
     * Auth methods are Clicksign's documented `auth` values for a
     * `provide_evidence` requirement.
     * https://developers.clicksign.com/docs/tipos-de-requisitos-de-autenticacao
     *
     * Only the ones with no per-use charge are offered. The billed biometric
     * checks (facial_biometrics, biometric, identity_biometrics,
     * documentscopy) are documented and implementable, but exposing them in a
     * picker invites a surprise invoice; they are left out until the UI can
     * show a price next to each. `presential`, `embedded_signature` and
     * `auto_signature` are also omitted — they need a different flow than the
     * one this driver implements, and half-supporting them would be worse
     * than not offering them.
     */
    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(
            // Clicksign authenticates with a single access token, sent raw in
            // the Authorization header.
            credentialFields: [
                [
                    'key'      => 'access_token',
                    'label'    => 'Access Token',
                    'type'     => 'password',
                    'required' => true,
                    'help'     => 'Gerado no painel da Clicksign em Configurações → API. '
                        . 'Cada ambiente (sandbox e produção) tem o seu próprio token.',
                ],
            ],
            environments: ['sandbox', 'production'],
            authMethods: [
                [
                    'key'      => 'email',
                    'label'    => 'Token por e-mail',
                    'requires' => ['email'],
                    'billed'   => false,
                ],
                [
                    'key'      => 'sms',
                    'label'    => 'Token por SMS',
                    'requires' => ['phone'],
                    'billed'   => false,
                ],
                [
                    'key'      => 'whatsapp',
                    'label'    => 'Token por WhatsApp',
                    'requires' => ['phone'],
                    'billed'   => false,
                ],
                [
                    'key'      => 'icp_brasil',
                    'label'    => 'Certificado digital ICP-Brasil',
                    'requires' => [],
                    'billed'   => false,
                ],
                [
                    'key'      => 'pix',
                    'label'    => 'Autenticação via Pix',
                    'requires' => ['document_number'],
                    'billed'   => false,
                    'note'     => 'Substitui o token; exige CPF do signatário.',
                ],
                [
                    'key'      => 'handwritten',
                    'label'    => 'Assinatura manuscrita',
                    'requires' => [],
                    'billed'   => false,
                ],
            ],
            // https://developers.clicksign.com/docs/documentos
            acceptedMimeTypes: [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'image/png',
                'image/jpeg',
                'text/plain',
            ],
            // Clicksign does not publish a maximum upload size. Null means
            // "the vendor states no limit"; the CRM applies its own ceiling at
            // the controller instead of asserting one on Clicksign's behalf.
            maxFileBytes: null,
            locales: ['pt-BR', 'en-US'],
            // Documented allowed values for remind_interval; null = no reminders.
            reminderIntervals: [1, 2, 3, 7, 14, null],
            registersWebhooks: true,
            supportsSigningOrder: true,
            supportsCancel: true,
            supportsResend: true,
            supportsMultipleDocuments: true,
            supportsDeadline: true,
            supportsRefusal: true,
            requiredSignerFields: ['email'],
        );
    }

    /* ============================================================
     | Credentials and webhooks
     ============================================================ */

    public function verifyCredentials(SignatureProviderAccount $account): ConnectionResult
    {
        try {
            // The lightest documented authenticated call: list one envelope.
            $response = $this->client($account)->get(
                '/envelopes',
                'verify_credentials',
                ['page[size]' => 1]
            );

            return ConnectionResult::ok('Clicksign conectado com sucesso.', [
                'environment' => $account->environment,
                'envelopes'   => count($response['data'] ?? []),
            ]);
        } catch (SignatureProviderException $e) {
            return ConnectionResult::failed($e->getMessage(), [
                'status_code' => $e->statusCode,
                'environment' => $account->environment,
            ]);
        }
    }

    public function registerWebhook(SignatureProviderAccount $account, string $callbackUrl): WebhookRegistration
    {
        $response = $this->client($account)->post('/webhooks', [
            'data' => [
                'type'       => 'webhooks',
                'attributes' => [
                    'endpoint' => $callbackUrl,
                    'status'   => 'active',
                    'events'   => ClicksignEventMap::SUBSCRIBED_EVENTS,
                ],
            ],
        ], 'register_webhook');

        $data = $response['data'] ?? [];

        return new WebhookRegistration(
            externalId: (string) ($data['id'] ?? ''),
            // Shown once, at registration — the caller must persist it now.
            secret: $data['attributes']['secret'] ?? null,
            endpoint: $callbackUrl,
            events: $data['attributes']['events'] ?? ClicksignEventMap::SUBSCRIBED_EVENTS,
        );
    }

    public function removeWebhook(SignatureProviderAccount $account): void
    {
        if (empty($account->webhook_external_id)) {
            return;
        }

        try {
            $this->client($account)->delete(
                "/webhooks/{$account->webhook_external_id}",
                'remove_webhook'
            );
        } catch (SignatureProviderException $e) {
            // Already gone at the vendor is the outcome we wanted.
            if ($e->statusCode === 404) {
                return;
            }

            throw $e;
        }
    }

    /* ============================================================
     | Sending
     ============================================================ */

    /**
     * Clicksign's documented payload rules, checked before we spend a call.
     *
     * Each of these is a rule the API states and enforces; hitting them at
     * the vendor produces a JSON:API 422 that means little to a salesperson,
     * so they are caught here with wording that says what to fix.
     */
    public function validateDraft(SignatureRequest $request): array
    {
        $errors       = [];
        $capabilities = $this->capabilities();

        $documents = $request->relationLoaded('documents') ? $request->documents : $request->documents()->get();
        $signers   = $request->relationLoaded('signers') ? $request->signers : $request->signers()->get();

        if ($documents->isEmpty()) {
            $errors[] = 'Adicione ao menos um documento antes de enviar para assinatura.';
        }

        if ($signers->isEmpty()) {
            $errors[] = 'Adicione ao menos um signatário antes de enviar para assinatura.';
        }

        foreach ($documents as $document) {
            if ($document->mime && !in_array($document->mime, $capabilities->acceptedMimeTypes, true)) {
                $errors[] = "O arquivo '{$document->filename}' tem um formato que a Clicksign não aceita ({$document->mime}).";
            }
        }

        // Documented allowed values: 1, 2, 3, 7, 14 or null.
        if ($request->reminder_interval !== null
            && !in_array((int) $request->reminder_interval, [1, 2, 3, 7, 14], true)) {
            $errors[] = 'O intervalo de lembretes deve ser 1, 2, 3, 7 ou 14 dias.';
        }

        if (!in_array($request->locale, $capabilities->locales, true)) {
            $errors[] = "O idioma '{$request->locale}' não é suportado pela Clicksign.";
        }

        foreach ($signers as $signer) {
            $label = $signer->name ?: "signatário #{$signer->id}";

            // Documented: "name (required, minimum 2 words)".
            if (count(preg_split('/\s+/', trim((string) $signer->name), -1, PREG_SPLIT_NO_EMPTY)) < 2) {
                $errors[] = "O nome do signatário '{$label}' precisa ter nome e sobrenome.";
            }

            $methods       = $signer->auth_methods ?: ['email'];
            $communication = $this->communicateEvents($signer);

            foreach ($methods as $method) {
                if (!$capabilities->supportsAuthMethod($method)) {
                    $errors[] = "A autenticação '{$method}' não está disponível para a Clicksign.";
                    continue;
                }

                foreach ($capabilities->requirementsForAuthMethod($method) as $field) {
                    if (empty($signer->{$this->signerFieldColumn($field)})) {
                        $errors[] = "O signatário '{$label}' precisa de {$this->signerFieldLabel($field)} "
                            . "para a autenticação '{$method}'.";
                    }
                }
            }

            // Email is required whenever any notification goes by email.
            if (in_array('email', $communication, true) && empty($signer->email)) {
                $errors[] = "O signatário '{$label}' precisa de e-mail para receber as notificações.";
            }

            // Phone is required whenever any notification goes by SMS/WhatsApp.
            if ((in_array('sms', $communication, true) || in_array('whatsapp', $communication, true))
                && empty($signer->phone)) {
                $errors[] = "O signatário '{$label}' precisa de telefone para receber as notificações.";
            }

            // Documented: 10 or 11 digits.
            if (!empty($signer->phone)) {
                $digits = preg_replace('/\D+/', '', $signer->phone);

                if (strlen($digits) < 10 || strlen($digits) > 11) {
                    $errors[] = "O telefone do signatário '{$label}' deve ter 10 ou 11 dígitos (DDD + número).";
                }
            }

            // Documented CPF format: 000.000.000-00.
            if (!empty($signer->document_number)
                && !preg_match('/^\d{3}\.\d{3}\.\d{3}-\d{2}$/', $signer->document_number)) {
                $errors[] = "O CPF do signatário '{$label}' deve estar no formato 000.000.000-00.";
            }
        }

        return array_values(array_unique($errors));
    }

    private function signerFieldColumn(string $field): string
    {
        return match ($field) {
            'document_number' => 'document_number',
            'phone'           => 'phone',
            'birthdate'       => 'birthdate',
            default           => 'email',
        };
    }

    private function signerFieldLabel(string $field): string
    {
        return match ($field) {
            'document_number' => 'CPF',
            'phone'           => 'telefone',
            'birthdate'       => 'data de nascimento',
            default           => 'e-mail',
        };
    }

    public function createRequest(SignatureRequest $request): void
    {
        $account = $request->providerAccount;
        $client  = $this->client($account);

        // 1. Envelope — skipped when a retry already created it.
        if (empty($request->provider_request_id)) {
            $response = $client->post('/envelopes', [
                'data' => [
                    'type'       => 'envelopes',
                    'attributes' => $this->envelopeAttributes($request),
                ],
            ], 'create_envelope', $request);

            $envelopeId = data_get($response, 'data.id');

            if (empty($envelopeId)) {
                throw SignatureProviderException::permanent(
                    'Clicksign did not return an envelope id when creating the request.'
                );
            }

            // Persisted immediately: everything below is addressed to it, and
            // a crash here must not orphan a billed envelope.
            $request->forceFill(['provider_request_id' => $envelopeId])->save();
        }

        $envelopeId = $request->provider_request_id;

        // 2. Documents.
        foreach ($request->documents as $document) {
            if (!empty($document->provider_document_id)) {
                continue;
            }

            $response = $client->post("/envelopes/{$envelopeId}/documents", [
                'data' => [
                    'type'       => 'documents',
                    'attributes' => [
                        'filename'       => $document->filename,
                        // The `data:<mime>;base64,` prefix is required; a bare
                        // base64 string is rejected.
                        'content_base64' => $this->encodeDocument($document),
                    ],
                ],
            ], 'upload_document', $request);

            $document->forceFill([
                'provider_document_id' => data_get($response, 'data.id'),
            ])->save();
        }

        // 3. Signers.
        foreach ($request->signers as $signer) {
            if (!empty($signer->provider_signer_id)) {
                continue;
            }

            $response = $client->post("/envelopes/{$envelopeId}/signers", [
                'data' => [
                    'type'       => 'signers',
                    'attributes' => $this->signerAttributes($signer),
                ],
            ], 'create_signer', $request);

            $signer->forceFill([
                'provider_signer_id' => data_get($response, 'data.id'),
            ])->save();
        }

        // 4. Requirements: one qualification plus one per auth method, for
        //    every (document, signer) pair. Tracked in metadata so a retry
        //    does not recreate the ones that already landed.
        $this->createRequirements($request, $client);

        // 5. Activate.
        $client->patch("/envelopes/{$envelopeId}", [
            'data' => [
                'id'         => $envelopeId,
                'type'       => 'envelopes',
                'attributes' => ['status' => 'running'],
            ],
        ], 'activate_envelope', $request);

        // 6. Invite. Activation alone sends nothing.
        $client->post("/envelopes/{$envelopeId}/notifications", [
            'data' => [
                'type'       => 'notifications',
                'attributes' => new \stdClass(),
            ],
        ], 'notify_envelope', $request);
    }

    public function cancelRequest(SignatureRequest $request): void
    {
        if (empty($request->provider_request_id)) {
            return;
        }

        $this->client($request->providerAccount)->patch(
            "/envelopes/{$request->provider_request_id}",
            [
                'data' => [
                    'id'         => $request->provider_request_id,
                    'type'       => 'envelopes',
                    'attributes' => ['status' => 'canceled'],
                ],
            ],
            'cancel_envelope',
            $request
        );
    }

    public function notify(SignatureRequest $request, ?SignatureSigner $signer = null): void
    {
        $envelopeId = $request->provider_request_id;

        if (empty($envelopeId)) {
            throw SignatureProviderException::permanent(
                'This request has not been sent to Clicksign yet, so there is nothing to resend.'
            );
        }

        $client = $this->client($request->providerAccount);

        if ($signer && $signer->provider_signer_id) {
            $attributes = [];

            if (!empty($request->message)) {
                $attributes['message'] = $request->message;
            }

            $client->post(
                "/envelopes/{$envelopeId}/signers/{$signer->provider_signer_id}/notifications",
                ['data' => ['type' => 'notifications', 'attributes' => $attributes ?: new \stdClass()]],
                'notify_signer',
                $request
            );

            return;
        }

        $client->post(
            "/envelopes/{$envelopeId}/notifications",
            ['data' => ['type' => 'notifications', 'attributes' => new \stdClass()]],
            'notify_envelope',
            $request
        );
    }

    /**
     * Request-level state from the vendor.
     *
     * DOCUMENTED LIMITATION — no signer states are returned. Clicksign's
     * signer resource (GET /envelopes/{id}/signers/{signer_id}) carries only
     * profile fields: name, email, phone, documentation, group,
     * communicate_events, created, modified. It has no status, no signed_at
     * and no signature URL, so there is nothing to reconcile a signer against.
     * Per-signer progress therefore comes from webhooks only, and this method
     * returns an empty signer list rather than inventing one.
     */
    public function fetchStatus(SignatureRequest $request): RemoteRequestState
    {
        if (empty($request->provider_request_id)) {
            return new RemoteRequestState(status: $request->status);
        }

        $response = $this->client($request->providerAccount)->get(
            "/envelopes/{$request->provider_request_id}",
            'fetch_envelope',
            [],
            $request
        );

        $remoteStatus = data_get($response, 'data.attributes.status');
        $mapped       = ClicksignEventMap::envelopeStatus($remoteStatus);

        if ($mapped === null) {
            Log::info('Clicksign returned an envelope status the driver does not map', [
                'signature_request_id' => $request->id,
                'remote_status'        => $remoteStatus,
            ]);

            $mapped = $request->status;
        }

        return new RemoteRequestState(
            status: $mapped,
            signers: [],
            completedAt: $mapped === SignatureStatus::COMPLETED ? now() : null,
            raw: $response,
        );
    }

    /**
     * Fetch the finished file.
     *
     * Confirmed against sandbox: signed files live on presigned S3 links with
     * `X-Amz-Expires=299` — five minutes. The URL the webhook delivered is
     * therefore already stale by the time any retry runs, so it is treated as
     * a hint, not an address. A fresh link is minted per attempt from
     *
     *   GET /envelopes/{envelope}/documents/{document} → data.links.files.signed
     *
     * which re-signs on every call. Only if that lookup yields nothing do we
     * fall back to the delivered URL (resolved through `download_base_url`
     * when it arrives relative, as the docs' example suggests it can).
     */
    public function downloadSignedDocument(SignatureDocument $document, string $url): DownloadedFile
    {
        $resolved = $this->freshSignedFileUrl($document) ?? $this->resolveDownloadUrl($url);

        $contents = $this->client($document->signatureRequest->providerAccount)->download(
            $resolved,
            'download_signed_document',
            $document->signatureRequest
        );

        return new DownloadedFile(
            contents: $contents,
            filename: $this->signedFilename($document),
            mimeType: 'application/pdf',
        );
    }

    /* ============================================================
     | Webhooks
     ============================================================ */

    /**
     * Clicksign signs deliveries with `Content-Hmac: sha256=<hex>`, an
     * HMAC-SHA256 of the raw request body keyed with the webhook secret
     * issued at registration.
     * https://developers.clicksign.com/docs/seguranca-de-webhooks
     *
     * The body must be taken raw. A payload that has been decoded and
     * re-encoded will not reproduce the bytes the signature covers.
     */
    public function verifyWebhook(SignatureProviderAccount $account, HttpRequest $httpRequest): bool
    {
        $secret = $account->webhook_secret;

        if (empty($secret)) {
            Log::warning('Clicksign webhook rejected: no secret stored for this account', [
                'signature_provider_id' => $account->id,
            ]);

            return false;
        }

        $provided = (string) $httpRequest->header('Content-Hmac', '');

        if ($provided === '') {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $httpRequest->getContent(), $secret);

        return hash_equals($expected, $provided);
    }

    public function parseWebhook(SignatureProviderAccount $account, HttpRequest $httpRequest): array
    {
        $raw     = $httpRequest->getContent();
        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            return [];
        }

        // Clicksign's own guidance is to filter on the `Event` header rather
        // than parsing the body, so it wins when both are present.
        $eventName = (string) ($httpRequest->header('Event') ?: data_get($payload, 'event.name', ''));

        if ($eventName === '') {
            return [];
        }

        $document = data_get($payload, 'document', []);
        $document = is_array($document) ? $document : [];

        $occurredAt = data_get($payload, 'event.occurred_at');

        return [new NormalizedEvent(
            type: ClicksignEventMap::eventType($eventName),
            providerEventName: $eventName,
            // See resolveRequestReference(): what `document.key` identifies is
            // not stated for API 3.0, so the applier is given it and matches
            // defensively against both document and envelope ids.
            providerRequestId: data_get($document, 'key'),
            providerSignerId: $this->extractSignerId($payload),
            signerEmail: $this->extractSignerEmail($payload),
            occurredAt: $occurredAt ? Carbon::parse($occurredAt) : now(),
            signedFileUrl: data_get($document, 'downloads.signed_file_url'),
            payload: $payload,
            // Clicksign sends no event id, so identity is the delivery's own
            // content. Two identical bodies are the same event; the hash is
            // stable across redeliveries, which is exactly what the unique
            // index on signature_events needs.
            externalId: null,
        )];
    }

    /* ============================================================
     | Internals
     ============================================================ */

    private function client(?SignatureProviderAccount $account): ClicksignClient
    {
        if (!$account) {
            throw SignatureProviderException::permanent(
                'This signature request has no Clicksign account attached.'
            );
        }

        return new ClicksignClient($account, $this->audit);
    }

    /** Documented envelope attributes; nulls are dropped rather than sent. */
    private function envelopeAttributes(SignatureRequest $request): array
    {
        $attributes = [
            'name'                => $request->title,
            'locale'              => $request->locale,
            'auto_close'          => (bool) $request->auto_close,
            'block_after_refusal' => (bool) $request->block_after_refusal,
        ];

        // Documented allowed values only: 1, 2, 3, 7, 14 or null.
        if ($request->reminder_interval !== null) {
            $attributes['remind_interval'] = (int) $request->reminder_interval;
        }

        if ($request->deadline_at) {
            // RFC 3339, as documented.
            $attributes['deadline_at'] = $request->deadline_at->toRfc3339String();
        }

        if (!empty($request->subject)) {
            $attributes['default_subject'] = $request->subject;
        }

        if (!empty($request->message)) {
            $attributes['default_message'] = $request->message;
        }

        return $attributes;
    }

    private function signerAttributes(SignatureSigner $signer): array
    {
        $attributes = [
            'name'      => $signer->name,
            'group'     => (int) $signer->signing_group,
            'refusable' => (bool) $signer->refusable,
        ];

        if (!empty($signer->email)) {
            $attributes['email'] = $signer->email;
        }

        if (!empty($signer->phone)) {
            // Documented as 10–11 digits, so send digits only.
            $attributes['phone_number'] = preg_replace('/\D+/', '', $signer->phone);
        }

        if (!empty($signer->document_number)) {
            $attributes['documentation']     = $signer->document_number;
            $attributes['has_documentation'] = true;
        }

        if ($signer->birthdate) {
            $attributes['birthday'] = $signer->birthdate->format('Y-m-d');
        }

        $attributes['communicate_events'] = $this->communicateEvents($signer);

        return $attributes;
    }

    /**
     * How Clicksign should reach this signer. Documented allowed values:
     *   signature_request  none | email | whatsapp | sms
     *   signature_reminder none | email
     *   document_signed    email | whatsapp
     */
    private function communicateEvents(SignatureSigner $signer): array
    {
        $configured = $signer->communication ?? [];

        return [
            'signature_request'  => $configured['signature_request'] ?? 'email',
            'signature_reminder' => $configured['signature_reminder'] ?? 'email',
            'document_signed'    => $configured['document_signed'] ?? 'email',
        ];
    }

    /**
     * Step 4, with bookkeeping.
     *
     * Requirements have no natural idempotency at the vendor — posting the
     * same pair twice creates two. The keys of the ones that succeeded are
     * kept in the request's metadata so a retry resumes instead of duplicating.
     */
    private function createRequirements(SignatureRequest $request, ClicksignClient $client): void
    {
        $envelopeId = $request->provider_request_id;
        $metadata   = $request->metadata ?? [];
        $created    = data_get($metadata, 'clicksign.requirements', []);

        foreach ($request->documents as $document) {
            foreach ($request->signers as $signer) {
                $pair = "{$document->provider_document_id}:{$signer->provider_signer_id}";

                // Qualification: this signer signs this document.
                $qualificationKey = "{$pair}:qualification";

                if (!in_array($qualificationKey, $created, true)) {
                    $client->post("/envelopes/{$envelopeId}/requirements", [
                        'data' => [
                            'type'          => 'requirements',
                            'attributes'    => ['action' => 'agree', 'role' => 'sign'],
                            'relationships' => $this->requirementRelationships($document, $signer),
                        ],
                    ], 'create_requirement_qualification', $request);

                    $created[] = $qualificationKey;
                    $this->rememberRequirements($request, $created);
                }

                // Authentication: how they prove who they are. At least one is
                // required for the signature to be collectable.
                foreach ($signer->auth_methods ?: ['email'] as $method) {
                    $authKey = "{$pair}:auth:{$method}";

                    if (in_array($authKey, $created, true)) {
                        continue;
                    }

                    $client->post("/envelopes/{$envelopeId}/requirements", [
                        'data' => [
                            'type'          => 'requirements',
                            'attributes'    => ['action' => 'provide_evidence', 'auth' => $method],
                            'relationships' => $this->requirementRelationships($document, $signer),
                        ],
                    ], 'create_requirement_auth', $request);

                    $created[] = $authKey;
                    $this->rememberRequirements($request, $created);
                }
            }
        }
    }

    private function requirementRelationships(SignatureDocument $document, SignatureSigner $signer): array
    {
        return [
            'document' => ['data' => ['type' => 'documents', 'id' => $document->provider_document_id]],
            'signer'   => ['data' => ['type' => 'signers', 'id' => $signer->provider_signer_id]],
        ];
    }

    private function rememberRequirements(SignatureRequest $request, array $created): void
    {
        $metadata = $request->metadata ?? [];
        data_set($metadata, 'clicksign.requirements', array_values(array_unique($created)));

        $request->forceFill(['metadata' => $metadata])->save();
    }

    /** The file, as the documented `data:<mime>;base64,<content>` string. */
    private function encodeDocument(SignatureDocument $document): string
    {
        $disk = Storage::disk($document->disk ?? 'local');

        if (!$disk->exists($document->source_path)) {
            throw SignatureProviderException::permanent(
                "The file for document [{$document->id}] is missing from storage at [{$document->source_path}]."
            );
        }

        $mime = $document->mime ?: 'application/pdf';

        return "data:{$mime};base64," . base64_encode($disk->get($document->source_path));
    }

    /**
     * Re-sign the signed-file link, or null if it cannot be obtained.
     *
     * Never throws: this is an optimisation over the delivered URL, and a
     * failure here must fall through to that rather than lose the download.
     */
    private function freshSignedFileUrl(SignatureDocument $document): ?string
    {
        $request = $document->signatureRequest;

        if (empty($request?->provider_request_id) || empty($document->provider_document_id)) {
            return null;
        }

        try {
            $response = $this->client($request->providerAccount)->get(
                "/envelopes/{$request->provider_request_id}/documents/{$document->provider_document_id}",
                'refresh_signed_file_url',
                [],
                $request
            );
        } catch (\Throwable $e) {
            Log::warning('Could not refresh the Clicksign signed-file URL; falling back to the delivered one', [
                'signature_document_id' => $document->id,
                'error'                 => $e->getMessage(),
            ]);

            return null;
        }

        $fresh = data_get($response, 'data.links.files.signed');

        return is_string($fresh) && $fresh !== '' ? $fresh : null;
    }

    private function resolveDownloadUrl(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $base = config('signatures.providers.clicksign.download_base_url');

        if (empty($base)) {
            throw SignatureProviderException::permanent(
                'Clicksign returned a relative download path (' . $url . ') and no download host is configured. '
                . 'Clicksign does not document which host these paths belong to; set '
                . 'CLICKSIGN_DOWNLOAD_BASE_URL once it has been confirmed against a real delivery. '
                . 'The signature itself completed — only the file copy is pending.'
            );
        }

        return rtrim($base, '/') . '/' . ltrim($url, '/');
    }

    private function signedFilename(SignatureDocument $document): string
    {
        $name = pathinfo($document->filename, PATHINFO_FILENAME) ?: 'documento';

        return "{$name}-assinado.pdf";
    }

    /**
     * Best-effort signer identity from a delivery.
     *
     * Clicksign documents the payload's top-level shape (`event.name`,
     * `event.data`, `document`) and the `document` object's fields, but not
     * which key inside `event.data` identifies the signer on a `sign` or
     * `refusal` event. Rather than assume one, this probes the plausible
     * documented-adjacent locations and returns null when none match — the
     * applier then falls back to matching on email, and logs an unattributed
     * event instead of guessing at a signer.
     */
    private function extractSignerId(array $payload): ?string
    {
        foreach (['event.data.signer.key', 'event.data.signer.id', 'event.data.signer_key'] as $path) {
            $value = data_get($payload, $path);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function extractSignerEmail(array $payload): ?string
    {
        foreach (['event.data.signer.email', 'event.data.user.email', 'event.data.email'] as $path) {
            $value = data_get($payload, $path);

            if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return $value;
            }
        }

        return null;
    }
}
