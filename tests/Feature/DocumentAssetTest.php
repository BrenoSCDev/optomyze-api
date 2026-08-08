<?php

use App\Models\CompanyFile;
use App\Services\Documents\DocumentAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Images in templates: stored once, referenced by URL, inlined at generation.
 *
 * The security half of this file matters more than the feature half. A
 * template body is user-authored input that ends up as an `<img src>` the
 * server itself resolves — which is a server-side request forgery primitive if
 * the resolver is willing to fetch whatever it is given, and a directory
 * traversal if it is willing to read whatever path it is handed.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

function assetService(): DocumentAssetService
{
    return app(DocumentAssetService::class);
}

/** A real 2x2 PNG — Storage::fake still runs mime detection on read. */
function pngBytes(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAEklEQVR4nGP8z4APMOGVHW'
        . 'YFAB0EAQlz9nCuAAAAAElFTkSuQmCC'
    );
}

test('an upload is stored on disk and counted against the company', function () {
    $company = docCompany();

    $result = assetService()->store(
        UploadedFile::fake()->createWithContent('logo.png', pngBytes()),
        $company->id
    );

    Storage::disk('local')->assertExists($result['path']);

    expect($result['path'])->toStartWith("documents/{$company->id}/assets/")
        ->and($result['url'])->toContain('/documents/assets/');

    // Plan usage is computed from this ledger; an asset that never lands in it
    // is storage the customer is using and not being shown.
    $this->assertDatabaseHas('company_files', [
        'company_id' => $company->id,
        'path'       => $result['path'],
        'category'   => CompanyFile::CATEGORY_DOCUMENT_ASSET,
    ]);
});

test('our own asset is embedded as a data uri at generation', function () {
    $company = docCompany();
    $service = assetService();

    $stored = $service->store(
        UploadedFile::fake()->createWithContent('logo.png', pngBytes()),
        $company->id
    );

    $inlined = $service->inlineAssets('<p><img src="' . e($stored['url']) . '"></p>');

    expect($inlined)->toContain('data:image/png;base64,')
        // The reference must be gone: dompdf cannot fetch it, and a PDF that
        // depends on a live URL stops being evidence the moment it moves.
        ->and($inlined)->not->toContain('signature=');
});

test('an image on someone else\'s host is left alone, never fetched', function () {
    // The AWS instance metadata endpoint: the canonical SSRF target. The
    // renderer keeps isRemoteEnabled off; this proves the inliner does not
    // reintroduce the capability one layer above it.
    $html = '<p><img src="http://169.254.169.254/latest/meta-data/"></p>';

    expect(assetService()->inlineAssets($html))->toBe($html);
});

test('a traversal dressed up as an asset url reads nothing', function () {
    $evil = 'http://localhost/api/documents/assets/'
        . base64_encode('../../../../etc/passwd') . '?signature=whatever';

    $html = '<p><img src="' . $evil . '"></p>';

    expect(assetService()->inlineAssets($html))->toBe($html);
});

test('a path outside the company assets prefix is refused', function () {
    Storage::disk('local')->put('documents/1/1/v1-CTR-2026-0001.pdf', 'not an image');

    // A real, existing file on our own disk — but not an asset. Confinement is
    // to the assets prefix, not merely to "somewhere we can read".
    $url = 'http://localhost/api/documents/assets/'
        . base64_encode('documents/1/1/v1-CTR-2026-0001.pdf') . '?signature=x';

    $html = '<p><img src="' . $url . '"></p>';

    expect(assetService()->inlineAssets($html))->toBe($html);
});

test('an already-embedded image is passed through untouched', function () {
    $html = '<p><img src="data:image/png;base64,AAAA"></p>';

    expect(assetService()->inlineAssets($html))->toBe($html);
});

test('a missing asset degrades to a broken image, not a failed contract', function () {
    $service = assetService();

    // A well-formed asset URL whose file was deleted. Generation must still
    // produce the document — the signature matters more than the logo.
    $url  = $service->publicUrl('documents/1/assets/gone.png');
    $html = '<p><img src="' . e($url) . '"></p>';

    expect(fn () => $service->inlineAssets($html))->not->toThrow(\Throwable::class)
        ->and($service->inlineAssets($html))->toContain('img');
});

test('several images in one body are all inlined', function () {
    $company = docCompany();
    $service = assetService();

    $a = $service->store(UploadedFile::fake()->createWithContent('a.png', pngBytes()), $company->id);
    $b = $service->store(UploadedFile::fake()->createWithContent('b.png', pngBytes()), $company->id);

    $inlined = $service->inlineAssets(
        '<img src="' . e($a['url']) . '"><p>between</p><img src="' . e($b['url']) . '">'
    );

    expect(substr_count($inlined, 'data:image/png;base64,'))->toBe(2);
});

test('uploading an asset requires template management rights', function () {
    $company = docCompany();

    $this->actingAs(docUser($company, 'agent'))
        ->postJson('/api/document-assets', [
            'file' => UploadedFile::fake()->createWithContent('logo.png', pngBytes()),
        ])
        ->assertStatus(403);
});

test('a non-image upload is refused', function () {
    $company = docCompany();

    $this->actingAs(docUser($company, 'admin'))
        ->postJson('/api/document-assets', [
            'file' => UploadedFile::fake()->createWithContent('payload.html', '<script>alert(1)</script>'),
        ])
        ->assertStatus(422);
});
