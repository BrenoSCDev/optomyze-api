<?php

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\DocumentType;
use App\Models\DocumentVariable;
use App\Models\Lead;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Documents\DocumentGenerator;
use App\Services\Documents\DocumentNumberGenerator;
use App\Services\Documents\Exceptions\DocumentRenderException;
use App\Services\Documents\Support\DocumentStatus;
use App\Services\Documents\VariableRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * The document engine: numbering, variable resolution, version immutability
 * and the authorization boundary.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

function docCompany(string $name = 'Doc Co', string $currency = 'BRL', string $language = 'PT'): Company
{
    return Company::create([
        'name'      => $name,
        'email'     => strtolower(str_replace(' ', '-', $name)) . '@example.test',
        'is_active' => true,
        'currency'  => $currency,
        'language'  => $language,
    ]);
}

function docUser(Company $company, string $role = 'admin'): User
{
    return User::factory()->create([
        'company_id' => $company->id,
        'email'      => "u{$role}-{$company->id}-" . uniqid() . '@example.test',
        'role'       => $role,
    ]);
}

function docType(Company $company, string $key = 'service_agreement'): DocumentType
{
    DocumentType::ensureSystemTypes($company->id);

    return DocumentType::fromCompany($company->id)->where('key', $key)->firstOrFail();
}

function docTemplate(Company $company, string $body, bool $published = true): DocumentTemplate
{
    return DocumentTemplate::create([
        'company_id'       => $company->id,
        'document_type_id' => docType($company)->id,
        'name'             => 'Modelo',
        'body_html'        => $body,
        'is_published'     => $published,
        'published_at'     => $published ? now() : null,
    ]);
}

function docFor(Company $company, DocumentTemplate $template, array $attributes = []): Document
{
    return Document::create(array_merge([
        'company_id'           => $company->id,
        'document_type_id'     => $template->document_type_id,
        'number'               => app(DocumentNumberGenerator::class)->generate($company->id, docType($company)),
        'title'                => 'Contrato',
        'document_template_id' => $template->id,
    ], $attributes));
}

/** Sales require a funnel and stage, so build the whole chain. */
function docSale(Company $company, Lead $lead, array $attributes = []): Sale
{
    $owner = docUser($company);

    $funnel = \App\Models\Funnel::create([
        'company_id' => $company->id,
        'name'       => 'Deals',
        'type'       => 'deal',
        'is_active'  => true,
        'created_by' => $owner->id,
    ]);

    $stage = \App\Models\Stage::create([
        'funnel_id' => $funnel->id,
        'name'      => 'New',
        'type'      => 'entry',
        'order'     => 1,
    ]);

    return Sale::create(array_merge([
        'company_id'    => $company->id,
        'lead_id'       => $lead->id,
        'user_id'       => $owner->id,
        'name'          => 'Projeto',
        'status'        => 'in_pipeline',
        'pricing_model' => 'product',
        'funnel_id'     => $funnel->id,
        'stage_id'      => $stage->id,
        'currency'      => 'BRL',
    ], $attributes));
}

/* ============================================================
 | Numbering
 ============================================================ */

test('numbers are sequential per company and carry the type prefix', function () {
    $company = docCompany();
    $type    = docType($company);

    $generator = app(DocumentNumberGenerator::class);
    $year      = now()->format('Y');

    expect($generator->generate($company->id, $type))->toBe("CTR-{$year}-0001")
        ->and($generator->generate($company->id, $type))->toBe("CTR-{$year}-0002");
});

test('numbering is independent between companies', function () {
    $a = docCompany('A');
    $b = docCompany('B');

    $generator = app(DocumentNumberGenerator::class);
    $year      = now()->format('Y');

    $generator->generate($a->id, docType($a));

    expect($generator->generate($b->id, docType($b)))->toBe("CTR-{$year}-0001");
});

test('a number is never handed out twice', function () {
    $company = docCompany();
    $type    = docType($company);

    $generator = app(DocumentNumberGenerator::class);

    $numbers = collect(range(1, 25))->map(fn () => $generator->generate($company->id, $type));

    expect($numbers->unique())->toHaveCount(25);
});

/* ============================================================
 | Generation and versioning
 ============================================================ */

test('generating produces a version with a stored pdf and resolved variables', function () {
    $company = docCompany();
    $user    = docUser($company);
    $lead    = Lead::factory()->create([
        'company_id' => $company->id,
        'first_name' => 'Maria',
        'last_name'  => 'Silva',
    ]);

    $template = docTemplate($company, '<p>Contratante: {{lead.name}} — {{document.number}}</p>');
    $document = docFor($company, $template, ['lead_id' => $lead->id]);

    $version = app(DocumentGenerator::class)->generate($document, $user);

    expect($version->version_number)->toBe(1)
        ->and($version->rendered_html)->toContain('Maria Silva')
        ->and($version->rendered_html)->toContain($document->number)
        ->and($version->pdf_size_bytes)->toBeGreaterThan(0)
        ->and($version->checksum)->toHaveLength(64);

    Storage::disk('local')->assertExists($version->pdf_path);

    // Variables are frozen alongside the version so it stays explainable.
    expect($version->variables['lead']['name'])->toBe('Maria Silva');
});

test('generating moves a draft to ready to send', function () {
    $company  = docCompany();
    $template = docTemplate($company, '<p>x</p>');
    $document = docFor($company, $template);

    expect($document->status)->toBe(DocumentStatus::DRAFT);

    app(DocumentGenerator::class)->generate($document);

    expect($document->fresh()->status)->toBe(DocumentStatus::READY_TO_SEND);
});

test('regenerating writes a new version and leaves the old one untouched', function () {
    $company  = docCompany();
    $lead     = Lead::factory()->create(['company_id' => $company->id, 'first_name' => 'Maria', 'last_name' => 'Silva']);
    $template = docTemplate($company, '<p>{{lead.name}}</p>');
    $document = docFor($company, $template, ['lead_id' => $lead->id]);

    $generator = app(DocumentGenerator::class);

    $v1 = $generator->generate($document);
    $v1Html     = $v1->rendered_html;
    $v1Checksum = $v1->checksum;

    // The world changes underneath the contract.
    $lead->update(['first_name' => 'Joana']);
    $template->update(['body_html' => '<p>REESCRITO {{lead.name}}</p>']);

    $v2 = $generator->generate($document->fresh());

    expect($v2->version_number)->toBe(2)
        ->and($v2->rendered_html)->toContain('REESCRITO')
        ->and($v2->rendered_html)->toContain('Joana');

    // Version 1 is evidence: it must read exactly as it did when signed.
    $v1 = $v1->fresh();
    expect($v1->rendered_html)->toBe($v1Html)
        ->and($v1->checksum)->toBe($v1Checksum)
        ->and($v1->rendered_html)->toContain('Maria Silva')
        ->and($v1->rendered_html)->not->toContain('REESCRITO');

    expect($document->fresh()->current_version_id)->toBe($v2->id);
});

test('an unpublished template cannot be generated from', function () {
    $company  = docCompany();
    $template = docTemplate($company, '<p>x</p>', published: false);
    $document = docFor($company, $template);

    expect(fn () => app(DocumentGenerator::class)->generate($document))
        ->toThrow(DocumentRenderException::class, 'não está publicado');
});

test('a document already out for signature cannot be regenerated', function () {
    $company  = docCompany();
    $template = docTemplate($company, '<p>x</p>');
    $document = docFor($company, $template, ['status' => DocumentStatus::WAITING_SIGNATURE]);

    expect(fn () => app(DocumentGenerator::class)->generate($document))
        ->toThrow(DocumentRenderException::class, 'já está em assinatura');
});

/* ============================================================
 | Variables
 ============================================================ */

test('deal line items render as a product table with currency-correct totals', function () {
    $company = docCompany(currency: 'BRL');
    $lead    = Lead::factory()->create(['company_id' => $company->id]);

    $sale = docSale($company, $lead, ['subtotal' => 15000, 'total' => 15000]);

    SaleItem::create([
        'sale_id'      => $sale->id,
        'product_name' => 'Consultoria',
        'quantity'     => 2,
        'unit_price'   => 2500,
        'total_price'  => 5000,
    ]);
    SaleItem::create([
        'sale_id'      => $sale->id,
        'product_name' => 'Suporte',
        'quantity'     => 1,
        'unit_price'   => 10000,
        'total_price'  => 10000,
    ]);

    // The loop sits inside a real <table>: dompdf rejects orphan rows, which
    // is the mistake a template author is most likely to make here.
    $template = docTemplate(
        $company,
        '<table>{% for item in deal.items %}<tr><td>{{item.product_name}}</td><td>{{item.total_price_formatted}}</td></tr>{% endfor %}</table><p>TOTAL {{deal.total_formatted}}</p>'
    );

    $document = docFor($company, $template, ['lead_id' => $lead->id, 'sale_id' => $sale->id]);
    $version  = app(DocumentGenerator::class)->generate($document);

    expect($version->rendered_html)->toContain('Consultoria')
        ->and($version->rendered_html)->toContain('Suporte')
        // Brazilian reais always read Brazilian-style, whatever the reader's
        // language — R$ 15,000.00 would mean fifteen reais to a Brazilian.
        ->and($version->rendered_html)->toContain('R$ 15.000,00');
});

test('a conditional selects the right clause from real deal data', function () {
    $company = docCompany();
    $lead    = Lead::factory()->create(['company_id' => $company->id]);

    $sale = docSale($company, $lead, ['name' => 'Grande', 'total' => 50000]);

    $template = docTemplate(
        $company,
        '{% if deal.total > 10000 %}CLAUSULA PREMIUM{% else %}CLAUSULA PADRAO{% endif %}'
    );

    $version = app(DocumentGenerator::class)->generate(
        docFor($company, $template, ['lead_id' => $lead->id, 'sale_id' => $sale->id])
    );

    expect($version->rendered_html)->toContain('CLAUSULA PREMIUM')
        ->and($version->rendered_html)->not->toContain('CLAUSULA PADRAO');
});

test('company-defined custom variables resolve', function () {
    $company = docCompany();

    DocumentVariable::create([
        'company_id' => $company->id,
        'key'        => 'cnpj',
        'label'      => 'CNPJ',
        'value'      => '12.345.678/0001-90',
    ]);

    $template = docTemplate($company, '<p>CNPJ: {{custom.cnpj}}</p>');
    $version  = app(DocumentGenerator::class)->generate(docFor($company, $template));

    expect($version->rendered_html)->toContain('12.345.678/0001-90');
});

test('the variable registry lists custom variables alongside crm ones', function () {
    $company = docCompany();

    DocumentVariable::create([
        'company_id' => $company->id,
        'key'        => 'jurisdicao',
        'label'      => 'Foro',
        'value'      => 'São Paulo',
    ]);

    $groups = collect(app(VariableRegistry::class)->catalog($company->id));

    expect($groups->pluck('group'))->toContain('lead', 'deal', 'document', 'custom');

    $custom = $groups->firstWhere('group', 'custom');
    expect(collect($custom['variables'])->pluck('token'))->toContain('custom.jurisdicao');
});

test('the registry reports which records a template needs', function () {
    $requirements = app(VariableRegistry::class)->requirementsFor([
        'lead.name', 'deal.total', 'customer.tax_id', 'document.number',
    ]);

    expect($requirements)->toContain('lead', 'sale', 'lead_company')
        ->and($requirements)->not->toContain('document');
});

/* ============================================================
 | The API boundary
 ============================================================ */

test('a document cannot be created against another company\'s lead', function () {
    $mine    = docCompany('Mine');
    $theirs  = docCompany('Theirs');
    $user    = docUser($mine);
    $theirLead = Lead::factory()->create(['company_id' => $theirs->id]);

    test()->actingAs($user)->postJson('/api/documents', [
        'document_type_id' => docType($mine)->id,
        'title'            => 'Tentativa',
        'lead_id'          => $theirLead->id,
    ])->assertStatus(404);
});

test('an agent cannot edit a document owned by someone else', function () {
    $company = docCompany();
    $owner   = docUser($company, 'admin');
    $agent   = docUser($company, 'agent');

    $template = docTemplate($company, '<p>x</p>');
    $document = docFor($company, $template, ['owner_id' => $owner->id, 'created_by' => $owner->id]);

    test()->actingAs($agent)
        ->putJson("/api/documents/{$document->id}", ['title' => 'Sequestrado'])
        ->assertStatus(403);

    expect($document->fresh()->title)->toBe('Contrato');
});

test('an agent can edit a document they own', function () {
    $company = docCompany();
    $agent   = docUser($company, 'agent');

    $template = docTemplate($company, '<p>x</p>');
    $document = docFor($company, $template, ['owner_id' => $agent->id, 'created_by' => $agent->id]);

    test()->actingAs($agent)
        ->putJson("/api/documents/{$document->id}", ['title' => 'Renomeado'])
        ->assertOk();

    expect($document->fresh()->title)->toBe('Renomeado');
});

test('an agent cannot manage templates', function () {
    $company = docCompany();
    $agent   = docUser($company, 'agent');

    test()->actingAs($agent)->postJson('/api/document-templates', [
        'name'      => 'Meu modelo',
        'body_html' => '<p>x</p>',
    ])->assertStatus(403);
});

test('the board only accepts statuses a human owns', function () {
    $company  = docCompany();
    $user     = docUser($company);
    $template = docTemplate($company, '<p>x</p>');
    $document = docFor($company, $template, ['owner_id' => $user->id]);

    // Dragging a card to "signed" would put a lie on a legal record.
    test()->actingAs($user)
        ->patchJson("/api/documents/{$document->id}/status", ['status' => DocumentStatus::SIGNED])
        ->assertStatus(422);

    test()->actingAs($user)
        ->patchJson("/api/documents/{$document->id}/status", ['status' => DocumentStatus::ARCHIVED])
        ->assertOk();

    expect($document->fresh()->status)->toBe(DocumentStatus::ARCHIVED);
});

test('a document out for signature cannot be dragged on the board', function () {
    $company  = docCompany();
    $user     = docUser($company);
    $template = docTemplate($company, '<p>x</p>');
    $document = docFor($company, $template, [
        'owner_id' => $user->id,
        'status'   => DocumentStatus::WAITING_SIGNATURE,
    ]);

    test()->actingAs($user)
        ->patchJson("/api/documents/{$document->id}/status", ['status' => DocumentStatus::DRAFT])
        ->assertStatus(422);
});

test('sending is refused until a version has been generated', function () {
    $company  = docCompany();
    $user     = docUser($company);
    $template = docTemplate($company, '<p>x</p>');
    $document = docFor($company, $template, ['owner_id' => $user->id]);

    test()->actingAs($user)
        ->postJson("/api/documents/{$document->id}/send", [
            'signers' => [['name' => 'Maria Silva', 'email' => 'maria@example.test']],
        ])
        ->assertStatus(422);
});

test('documents are listed scoped to the caller\'s company', function () {
    $mine   = docCompany('Mine');
    $theirs = docCompany('Theirs');

    docFor($mine, docTemplate($mine, '<p>a</p>'), ['title' => 'Meu']);
    docFor($theirs, docTemplate($theirs, '<p>b</p>'), ['title' => 'Deles']);

    $response = test()->actingAs(docUser($mine))->getJson('/api/documents')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.title'))->toBe('Meu');
});
