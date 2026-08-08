<?php

use App\Events\DocumentSigned;
use App\Jobs\DownloadSignedSignatureDocument;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Lead;
use App\Models\SignatureDocument;
use App\Models\SignatureEvent;
use App\Models\SignatureProviderAccount;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Models\User;
use App\Services\Documents\Support\DocumentStatus;
use App\Services\Signatures\Providers\Clicksign\ClicksignProvider;
use App\Services\Signatures\SignatureProviderRegistry;
use App\Services\Signatures\Support\SignatureStatus;
use App\Services\Signatures\Support\SignerStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FakeSignatureProvider;

/**
 * The digital signature module.
 *
 * The tests that matter here are the ones covering things that are easy to
 * get wrong and expensive when they are: webhook authentication, webhook
 * idempotency, and the provider abstraction actually being an abstraction.
 */
uses(RefreshDatabase::class);

function signatureCompany(string $name = 'Signature Co'): Company
{
    return Company::create([
        'name'      => $name,
        'email'     => strtolower(str_replace(' ', '-', $name)) . '@example.test',
        'is_active' => true,
    ]);
}

function signatureAccount(Company $company, string $secret = 'webhook-secret'): SignatureProviderAccount
{
    return SignatureProviderAccount::create([
        'company_id'        => $company->id,
        'provider'          => 'clicksign',
        'environment'       => 'sandbox',
        'credentials'       => ['access_token' => 'test-token'],
        'webhook_secret'    => $secret,
        'webhook_status'    => SignatureProviderAccount::WEBHOOK_REGISTERED,
        'connection_status' => SignatureProviderAccount::CONNECTION_CONNECTED,
        'is_active'         => true,
    ]);
}

/**
 * A signature request now always belongs to a document — the agreement is the
 * business record, and signing is a stage of its life.
 */
function signatureDocumentFor(SignatureProviderAccount $account, string $title = 'Contrato de teste'): Document
{
    DocumentType::ensureSystemTypes($account->company_id);

    $type = DocumentType::fromCompany($account->company_id)
        ->where('key', 'service_agreement')
        ->first();

    return Document::create([
        'company_id'       => $account->company_id,
        'document_type_id' => $type->id,
        'number'           => 'CTR-2026-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
        'title'            => $title,
        'status'           => DocumentStatus::WAITING_SIGNATURE,
        'sent_at'          => now(),
    ]);
}

function signatureRequestFor(
    SignatureProviderAccount $account,
    string $envelopeId = 'envelope-1',
    string $documentId = 'document-1',
    ?Document $document = null,
): SignatureRequest {
    $document ??= signatureDocumentFor($account);

    $request = SignatureRequest::create([
        'company_id'            => $account->company_id,
        'document_id'           => $document->id,
        'signature_provider_id' => $account->id,
        'provider'              => 'clicksign',
        'title'                 => $document->title,
        'status'                => SignatureStatus::SENT,
        'provider_request_id'   => $envelopeId,
        'sent_at'               => now(),
    ]);

    SignatureDocument::create([
        'company_id'           => $account->company_id,
        'signature_request_id' => $request->id,
        'provider_document_id' => $documentId,
        'filename'             => 'contrato.pdf',
        'mime'                 => 'application/pdf',
        'source_path'          => 'signature-documents/1/contrato.pdf',
        'source_checksum'      => hash('sha256', 'contrato'),
    ]);

    SignatureSigner::create([
        'company_id'           => $account->company_id,
        'signature_request_id' => $request->id,
        'provider_signer_id'   => 'signer-1',
        'name'                 => 'Maria Silva',
        'email'                => 'maria@example.test',
        'status'               => SignerStatus::NOTIFIED,
        'auth_methods'         => ['email'],
    ]);

    return $request->fresh(['documents', 'signers']);
}

/** A Clicksign-shaped delivery, signed the way Clicksign signs them. */
function deliverWebhook(
    SignatureProviderAccount $account,
    array $payload,
    ?string $secret = 'webhook-secret',
    ?string $callbackId = null,
) {
    $body = json_encode($payload);

    $headers = ['Event' => $payload['event']['name'] ?? 'sign'];

    if ($secret !== null) {
        $headers['Content-Hmac'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    return test()->call(
        'POST',
        '/api/signatures/webhook/clicksign/' . ($callbackId ?? $account->webhook_callback_id),
        [],
        [],
        [],
        collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_' . strtoupper(str_replace('-', '_', $k)) => $v])->all(),
        $body
    );
}

function signPayload(string $documentKey = 'document-1', string $email = 'maria@example.test'): array
{
    return [
        'event' => [
            'name'        => 'sign',
            'data'        => ['user' => ['email' => $email, 'name' => 'Maria Silva']],
            'occurred_at' => '2026-08-06T10:00:00-03:00',
        ],
        'document' => ['key' => $documentKey, 'status' => 'running'],
    ];
}

/* ============================================================
 | Webhook authentication
 ============================================================ */

test('a delivery signed with the stored secret is accepted', function () {
    $account = signatureAccount(signatureCompany());
    signatureRequestFor($account);

    deliverWebhook($account, signPayload())->assertOk();

    expect(SignatureEvent::count())->toBe(1);
});

test('a delivery with a wrong signature is rejected and changes nothing', function () {
    $account = signatureAccount(signatureCompany());
    $request = signatureRequestFor($account);

    deliverWebhook($account, signPayload(), 'the-wrong-secret')->assertStatus(401);

    expect(SignatureEvent::count())->toBe(0)
        ->and($request->fresh()->signers->first()->status)->toBe(SignerStatus::NOTIFIED);
});

test('a delivery with no signature header at all is rejected', function () {
    $account = signatureAccount(signatureCompany());
    signatureRequestFor($account);

    deliverWebhook($account, signPayload(), null)->assertStatus(401);

    expect(SignatureEvent::count())->toBe(0);
});

test('a delivery to an unknown callback id is acknowledged but ignored', function () {
    $account = signatureAccount(signatureCompany());
    signatureRequestFor($account);

    // Answered rather than errored: an unmatched callback is not our event,
    // and a 5xx would only make the vendor redeliver it forever.
    deliverWebhook($account, signPayload(), 'webhook-secret', (string) Str::uuid())
        ->assertStatus(202);

    expect(SignatureEvent::count())->toBe(0);
});

/* ============================================================
 | Idempotency — the guarantee the whole design rests on
 ============================================================ */

test('the same delivery twice produces one event and one state change', function () {
    $account = signatureAccount(signatureCompany());
    $request = signatureRequestFor($account);

    deliverWebhook($account, signPayload())->assertOk();
    deliverWebhook($account, signPayload())->assertOk();

    expect(SignatureEvent::count())->toBe(1);

    $signer = $request->fresh()->signers->first();
    expect($signer->status)->toBe(SignerStatus::SIGNED);
});

test('a redelivered completion does not queue a second download', function () {
    Queue::fake();

    $account = signatureAccount(signatureCompany());
    $request = signatureRequestFor($account);

    $payload = [
        'event'    => ['name' => 'auto_close', 'occurred_at' => '2026-08-06T11:00:00-03:00'],
        'document' => [
            'key'       => 'document-1',
            'status'    => 'closed',
            'downloads' => ['signed_file_url' => 'https://example.test/signed.pdf'],
        ],
    ];

    deliverWebhook($account, $payload)->assertOk();
    deliverWebhook($account, $payload)->assertOk();

    expect($request->fresh()->status)->toBe(SignatureStatus::COMPLETED);

    Queue::assertPushed(DownloadSignedSignatureDocument::class, 1);
});

test('events are deduplicated per provider, not globally', function () {
    $accountA = signatureAccount(signatureCompany('Company A'));
    $accountB = signatureAccount(signatureCompany('Company B'));

    signatureRequestFor($accountA, 'envelope-a', 'document-a');
    signatureRequestFor($accountB, 'envelope-b', 'document-b');

    deliverWebhook($accountA, signPayload('document-a'))->assertOk();
    deliverWebhook($accountB, signPayload('document-b'))->assertOk();

    expect(SignatureEvent::count())->toBe(2);
});

/* ============================================================
 | State transitions
 ============================================================ */

test('a completion marks every signer signed and stores the file url', function () {
    Queue::fake();

    $account = signatureAccount(signatureCompany());
    $request = signatureRequestFor($account);

    deliverWebhook($account, [
        'event'    => ['name' => 'document_closed', 'occurred_at' => '2026-08-06T12:00:00-03:00'],
        'document' => [
            'key'       => 'document-1',
            'status'    => 'closed',
            'downloads' => ['signed_file_url' => '/2026/08/06/abc_contrato_Clicksign.pdf'],
        ],
    ])->assertOk();

    $fresh = $request->fresh(['signers', 'documents']);

    expect($fresh->status)->toBe(SignatureStatus::COMPLETED)
        ->and($fresh->completed_at)->not->toBeNull()
        ->and($fresh->signers->first()->status)->toBe(SignerStatus::SIGNED)
        ->and($fresh->documents->first()->signed_file_url)->toBe('/2026/08/06/abc_contrato_Clicksign.pdf');
});

test('a refusal marks the request refused', function () {
    $account = signatureAccount(signatureCompany());
    $request = signatureRequestFor($account);

    deliverWebhook($account, [
        'event'    => ['name' => 'refusal', 'data' => ['user' => ['email' => 'maria@example.test']]],
        'document' => ['key' => 'document-1'],
    ])->assertOk();

    $fresh = $request->fresh(['signers']);

    expect($fresh->status)->toBe(SignatureStatus::REFUSED)
        ->and($fresh->signers->first()->status)->toBe(SignerStatus::REFUSED);
});

test('signer progress never moves backwards when events arrive out of order', function () {
    $account = signatureAccount(signatureCompany());
    $request = signatureRequestFor($account);

    // The signature lands first, then the "started" that preceded it.
    deliverWebhook($account, signPayload())->assertOk();
    deliverWebhook($account, [
        'event'    => ['name' => 'signature_started', 'data' => ['user' => ['email' => 'maria@example.test']]],
        'document' => ['key' => 'document-1'],
    ])->assertOk();

    expect($request->fresh()->signers->first()->status)->toBe(SignerStatus::SIGNED);
});

test('an unrecognised event is recorded without changing state', function () {
    $account = signatureAccount(signatureCompany());
    $request = signatureRequestFor($account);

    deliverWebhook($account, [
        'event'    => ['name' => 'update_locale'],
        'document' => ['key' => 'document-1'],
    ])->assertOk();

    expect(SignatureEvent::count())->toBe(1)
        ->and(SignatureEvent::first()->type)->toBe('settings_updated')
        ->and($request->fresh()->status)->toBe(SignatureStatus::SENT);
});

test('a delivery is matched by envelope id as well as document id', function () {
    $account = signatureAccount(signatureCompany());
    $request = signatureRequestFor($account);

    // Clicksign does not document whether document.key is the envelope or the
    // document under API 3.0, so both must resolve.
    deliverWebhook($account, signPayload('envelope-1'))->assertOk();

    expect($request->fresh()->signers->first()->status)->toBe(SignerStatus::SIGNED);
});

/* ============================================================
 | Provider-side validation
 ============================================================ */

test('clicksign rejects a one-word signer name before any api call', function () {
    $account = signatureAccount(signatureCompany());
    $request = signatureRequestFor($account);

    $request->signers()->first()->update(['name' => 'Maria']);

    $errors = app(ClicksignProvider::class)->validateDraft($request->fresh(['documents', 'signers']));

    expect($errors)->toContain("O nome do signatário 'Maria' precisa ter nome e sobrenome.");
});

test('clicksign rejects a malformed cpf', function () {
    $account = signatureAccount(signatureCompany());
    $request = signatureRequestFor($account);

    $request->signers()->first()->update(['document_number' => '12345678900']);

    $errors = app(ClicksignProvider::class)->validateDraft($request->fresh(['documents', 'signers']));

    expect(implode(' ', $errors))->toContain('000.000.000-00');
});

test('clicksign requires a cpf for pix authentication', function () {
    $account = signatureAccount(signatureCompany());
    $request = signatureRequestFor($account);

    $request->signers()->first()->update(['auth_methods' => ['pix'], 'document_number' => null]);

    $errors = app(ClicksignProvider::class)->validateDraft($request->fresh(['documents', 'signers']));

    expect(implode(' ', $errors))->toContain('CPF');
});

test('clicksign rejects a reminder interval it does not accept', function () {
    $account = signatureAccount(signatureCompany());
    $request = signatureRequestFor($account);

    $request->update(['reminder_interval' => 5]);

    $errors = app(ClicksignProvider::class)->validateDraft($request->fresh(['documents', 'signers']));

    expect(implode(' ', $errors))->toContain('1, 2, 3, 7 ou 14');
});

test('a valid draft produces no errors', function () {
    $account = signatureAccount(signatureCompany());
    $request = signatureRequestFor($account);

    expect(app(ClicksignProvider::class)->validateDraft($request))->toBe([]);
});

/* ============================================================
 | The abstraction itself
 ============================================================ */

test('the registry resolves the configured driver through the container', function () {
    $registry = app(SignatureProviderRegistry::class);

    expect($registry->defaultKey())->toBe('clicksign')
        ->and($registry->default())->toBeInstanceOf(ClicksignProvider::class)
        ->and($registry->supports('clicksign'))->toBeTrue()
        ->and($registry->supports('docusign'))->toBeFalse();
});

test('a new provider is added by config alone, with no core changes', function () {
    // The architectural claim, exercised: register a second driver purely
    // through config and it appears in the catalog the API and UI read from,
    // with its own credential fields and capabilities.
    config()->set('signatures.providers.acme', [
        'driver' => FakeSignatureProvider::class,
        'label'  => 'Acme Sign',
    ]);

    $registry = app(SignatureProviderRegistry::class);

    expect($registry->supports('acme'))->toBeTrue()
        ->and($registry->get('acme')->label())->toBe('Acme Sign');

    $catalog = collect($registry->catalog())->firstWhere('key', 'acme');

    expect($catalog)->not->toBeNull()
        ->and($catalog['capabilities']['credential_fields'][0]['key'])->toBe('api_key')
        ->and($catalog['capabilities']['supports_cancel'])->toBeTrue();
});

test('an unregistered provider key is refused rather than guessed at', function () {
    expect(fn () => app(SignatureProviderRegistry::class)->get('nope'))
        ->toThrow(\App\Services\Signatures\Exceptions\UnsupportedProviderException::class);
});

/* ============================================================
 | Tenancy
 ============================================================ */

test('a document belonging to another company is not visible', function () {
    $mine    = signatureCompany('Mine');
    $theirs  = signatureCompany('Theirs');
    $account = signatureAccount($theirs);
    $document = signatureDocumentFor($account);

    $user = User::factory()->create([
        'company_id' => $mine->id,
        'email'      => 'me@example.test',
        'role'       => 'admin',
    ]);

    test()->actingAs($user)
        ->getJson("/api/documents/{$document->id}")
        ->assertStatus(404);
});

test('a webhook cannot move another company\'s request', function () {
    $companyA = signatureCompany('A');
    $companyB = signatureCompany('B');

    $accountA = signatureAccount($companyA);
    $accountB = signatureAccount($companyB);

    // The request lives in company B; the delivery arrives on A's callback.
    signatureRequestFor($accountB, 'envelope-b', 'document-b');

    deliverWebhook($accountA, signPayload('document-b'))->assertOk();

    expect(SignatureEvent::count())->toBe(0);
});

/* ============================================================
 | The document lifecycle, driven by signature webhooks
 |
 | This is the join between the two modules: the provider reports on a
 | signature, and what actually moves is the agreement.
 ============================================================ */

test('a completion moves the document to signed and fires the domain event', function () {
    Event::fake([DocumentSigned::class]);
    Queue::fake();

    $account  = signatureAccount(signatureCompany());
    $document = signatureDocumentFor($account);
    signatureRequestFor($account, 'envelope-1', 'document-1', $document);

    deliverWebhook($account, [
        'event'    => ['name' => 'auto_close', 'occurred_at' => '2026-08-06T12:00:00-03:00'],
        'document' => [
            'key'       => 'document-1',
            'status'    => 'closed',
            'downloads' => ['signed_file_url' => 'https://example.test/signed.pdf'],
        ],
    ])->assertOk();

    expect($document->fresh()->status)->toBe(DocumentStatus::SIGNED)
        ->and($document->fresh()->signed_at)->not->toBeNull();

    Event::assertDispatched(DocumentSigned::class);
});

test('one signature of several moves the document to partially signed', function () {
    $account  = signatureAccount(signatureCompany());
    $document = signatureDocumentFor($account);
    $request  = signatureRequestFor($account, 'envelope-1', 'document-1', $document);

    // A second signer, so the first signature cannot be the last.
    SignatureSigner::create([
        'company_id'           => $account->company_id,
        'signature_request_id' => $request->id,
        'provider_signer_id'   => 'signer-2',
        'name'                 => 'Joao Souza',
        'email'                => 'joao@example.test',
        'status'               => SignerStatus::NOTIFIED,
        'auth_methods'         => ['email'],
    ]);

    deliverWebhook($account, signPayload())->assertOk();

    expect($document->fresh()->status)->toBe(DocumentStatus::PARTIALLY_SIGNED);
});

test('a refusal moves the document to rejected', function () {
    $account  = signatureAccount(signatureCompany());
    $document = signatureDocumentFor($account);
    signatureRequestFor($account, 'envelope-1', 'document-1', $document);

    deliverWebhook($account, [
        'event'    => ['name' => 'refusal', 'data' => ['user' => ['email' => 'maria@example.test']]],
        'document' => ['key' => 'document-1'],
    ])->assertOk();

    expect($document->fresh()->status)->toBe(DocumentStatus::REJECTED)
        ->and($document->fresh()->rejected_at)->not->toBeNull();
});

test('a deadline moves the document to expired', function () {
    $account  = signatureAccount(signatureCompany());
    $document = signatureDocumentFor($account);
    signatureRequestFor($account, 'envelope-1', 'document-1', $document);

    deliverWebhook($account, [
        'event'    => ['name' => 'deadline'],
        'document' => ['key' => 'document-1'],
    ])->assertOk();

    expect($document->fresh()->status)->toBe(DocumentStatus::EXPIRED);
});

test('a redelivered completion does not re-fire the signed event', function () {
    Event::fake([DocumentSigned::class]);
    Queue::fake();

    $account  = signatureAccount(signatureCompany());
    $document = signatureDocumentFor($account);
    signatureRequestFor($account, 'envelope-1', 'document-1', $document);

    $payload = [
        'event'    => ['name' => 'document_closed', 'occurred_at' => '2026-08-06T12:00:00-03:00'],
        'document' => ['key' => 'document-1', 'status' => 'closed'],
    ];

    deliverWebhook($account, $payload)->assertOk();
    deliverWebhook($account, $payload)->assertOk();

    // Anything hanging off DocumentSigned — closing the deal, raising an
    // invoice — must happen exactly once however often the vendor redelivers.
    Event::assertDispatchedTimes(DocumentSigned::class, 1);
});

/* ============================================================
 | Marketplace installation
 |
 | The card in the integrations grid posts to /api/integrations/{key} like
 | every other integration. Missing that route is what made the Clicksign
 | card fail with a 405 while the config modal itself worked fine.
 ============================================================ */

test('installing a signature provider from the marketplace creates its account', function () {
    $company = signatureCompany();
    $user    = User::factory()->create([
        'company_id' => $company->id,
        'email'      => 'installer@example.test',
        'role'       => 'admin',
    ]);

    test()->actingAs($user)
        ->postJson('/api/integrations/clicksign')
        ->assertStatus(201)
        ->assertJsonPath('account.provider', 'clicksign');

    expect(SignatureProviderAccount::fromCompany($company->id)->forProvider('clicksign')->exists())
        ->toBeTrue();
});

test('installing twice is idempotent rather than a unique-key error', function () {
    $company = signatureCompany();
    $user    = User::factory()->create([
        'company_id' => $company->id,
        'email'      => 'installer2@example.test',
        'role'       => 'admin',
    ]);

    test()->actingAs($user)->postJson('/api/integrations/clicksign')->assertStatus(201);
    test()->actingAs($user)->postJson('/api/integrations/clicksign')->assertStatus(200);

    expect(SignatureProviderAccount::fromCompany($company->id)->count())->toBe(1);
});

test('an installed provider reports as available so the card flips to configure', function () {
    $company = signatureCompany();
    $user    = User::factory()->create([
        'company_id' => $company->id,
        'email'      => 'available@example.test',
        'role'       => 'admin',
    ]);

    test()->actingAs($user)
        ->getJson('/api/integrations/available')
        ->assertOk()
        ->assertJsonPath('clicksign_available', false);

    test()->actingAs($user)->postJson('/api/integrations/clicksign')->assertStatus(201);

    test()->actingAs($user)
        ->getJson('/api/integrations/available')
        ->assertOk()
        ->assertJsonPath('clicksign_available', true);
});

test('installing an unregistered provider key is not swallowed by this route', function () {
    $company = signatureCompany();
    $user    = User::factory()->create([
        'company_id' => $company->id,
        'email'      => 'bogus@example.test',
        'role'       => 'admin',
    ]);

    // The route constraint is built from the registered drivers, so an unknown
    // key must not match it — otherwise this route would shadow every other
    // POST /integrations/{something}.
    test()->actingAs($user)
        ->postJson('/api/integrations/not-a-provider')
        ->assertStatus(405);
});
