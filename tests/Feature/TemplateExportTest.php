<?php

use App\Models\DocumentTemplate;
use App\Services\Documents\TemplateExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * Exporting the template itself, as PDF or Word.
 *
 * This is for the author — taking wording to a lawyer, keeping an offline
 * copy — not for the customer. So it produces no document, no contract number
 * and no version, and with no record supplied the variables stay *visible*
 * rather than resolving to blanks nobody can interpret.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

function richBody(): string
{
    return '<h1>CONTRATO</h1>'
        . '<p>Entre <strong>{{company.name}}</strong> e <strong>{{lead.name}}</strong>.</p>'
        . '<table><thead><tr><th>Produto</th><th>Total</th></tr></thead>'
        . '<tbody><tr><td>Implantação</td><td>R$ 2.000,00</td></tr></tbody></table>'
        . '<ul><li>Primeiro</li><li>Segundo</li></ul>'
        // The void elements that make the markup HTML-valid and XML-fatal.
        . '<p>Linha<br>quebrada</p><hr>'
        . '<div class="page-break" data-page-break=""></div>'
        . '<p>Depois da quebra.</p>';
}

function exportTemplate(): DocumentTemplate
{
    $company = docCompany();

    return DocumentTemplate::create([
        'company_id'       => $company->id,
        'document_type_id' => docType($company)->id,
        'name'             => 'Modelo de Teste',
        'body_html'        => richBody(),
        'variables_schema' => ['referenced' => ['company.name', 'lead.name']],
        'is_published'     => true,
    ]);
}

test('a template exports as a pdf', function () {
    $template = exportTemplate();

    $pdf = app(TemplateExporter::class)->toPdf(
        $template,
        app(TemplateExporter::class)->contextFor($template, null, null)
    );

    expect(substr($pdf, 0, 5))->toBe('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(1000);
});

test('a template exports as a real docx, not html with a renamed extension', function () {
    $template = exportTemplate();

    $docx = app(TemplateExporter::class)->toWord($template, []);

    // A .docx is a ZIP; "PK" is its signature. An HTML file with the extension
    // changed opens in Word but is broken for every other tool and for Word's
    // own track-changes, which is why this is asserted rather than assumed.
    expect(substr($docx, 0, 2))->toBe('PK');

    $path = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($path, $docx);

    $zip = new ZipArchive();
    expect($zip->open($path))->toBeTrue();

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    @unlink($path);

    expect($xml)->toBeString();

    // The constructs that a naive HTML→Word conversion silently drops.
    expect(preg_match_all('/<w:tbl[ >]/', $xml))->toBe(1)
        ->and(preg_match_all('/<w:tc[ >]/', $xml))->toBeGreaterThan(3)
        ->and(preg_match_all('/<w:numPr>/', $xml))->toBeGreaterThan(1)
        ->and($xml)->toContain('w:br w:type="page"');
});

test('void elements do not abort the word export', function () {
    // THE REGRESSION. PHPWord parses with DOMDocument::loadXML, which is
    // strict: `<br>` and `<hr>` are HTML-valid and XML-fatal, and one of them
    // aborted the whole export with "Opening and ending tag mismatch".
    $company = docCompany();

    $template = DocumentTemplate::create([
        'company_id' => $company->id,
        'name'       => 'Void elements',
        'body_html'  => '<p>Uma<br>duas</p><hr><p>três</p>',
    ]);

    expect(fn () => app(TemplateExporter::class)->toWord($template, []))
        ->not->toThrow(\Throwable::class);
});

test('without a record the variables stay visible instead of vanishing', function () {
    $template = exportTemplate();

    $context = app(TemplateExporter::class)->contextFor($template, null, null);

    // The renderer resolves an absent value to an empty string, so a plain
    // empty context would produce a contract full of unexplained gaps. Each
    // token resolves to a copy of itself instead.
    expect($context)->toHaveKey('company')
        ->and(data_get($context, 'company.name'))->toBe('{{company.name}}')
        ->and(data_get($context, 'lead.name'))->toBe('{{lead.name}}');
});

test('with a lead the variables resolve to real values', function () {
    $company  = docCompany();
    $template = DocumentTemplate::create([
        'company_id' => $company->id,
        'name'       => 'Resolved',
        'body_html'  => '<p>{{lead.name}}</p>',
        'variables_schema' => ['referenced' => ['lead.name']],
    ]);

    $lead = \App\Models\Lead::create([
        'company_id' => $company->id,
        'first_name' => 'Jordan',
        'last_name'  => 'Reyes',
    ]);

    $context = app(TemplateExporter::class)->contextFor($template, $lead->id, null);

    expect(data_get($context, 'lead.name'))->toBe('Jordan Reyes');
});

test('exporting creates no document and burns no contract number', function () {
    $template = exportTemplate();

    $before = \App\Models\Document::count();

    app(TemplateExporter::class)->toPdf($template, []);
    app(TemplateExporter::class)->toWord($template, []);

    expect(\App\Models\Document::count())->toBe($before);
});

test('another company cannot download a template', function () {
    $ours     = docCompany('Ours');
    $theirs   = docCompany('Theirs');

    $template = DocumentTemplate::create([
        'company_id' => $theirs->id,
        'name'       => 'Not yours',
        'body_html'  => '<p>Segredo</p>',
    ]);

    $this->actingAs(docUser($ours))
        ->get("/api/document-templates/{$template->id}/download?format=pdf")
        ->assertStatus(404);
});

test('an unsupported format is refused', function () {
    $template = exportTemplate();

    $this->actingAs(docUser(\App\Models\Company::find($template->company_id)))
        ->getJson("/api/document-templates/{$template->id}/download?format=exe")
        ->assertStatus(422);
});
