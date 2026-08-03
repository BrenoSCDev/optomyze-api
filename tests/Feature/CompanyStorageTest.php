<?php

use App\Models\Company;
use App\Models\CompanyFile;
use App\Models\Lead;
use App\Models\Sale;
use App\Models\User;
use App\Services\Storage\CompanyStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Storage accounting: every file the CRM writes is attributed to a company,
 * every file it deletes gives the bytes back, and the denormalised counter can
 * always be rebuilt from the ledger.
 */
uses(RefreshDatabase::class);

function storageCompany(string $name = 'Storage Co'): Company
{
    return Company::create([
        'name'             => $name,
        'email'            => strtolower(str_replace(' ', '-', $name)) . '@example.test',
        'is_active'        => true,
        'storage_limit_gb' => 5,
    ]);
}

function storageUser(Company $company): User
{
    return User::factory()->create([
        'company_id' => $company->id,
        'email'      => 'storage-' . $company->id . '-' . uniqid() . '@example.test',
        'role'       => 'admin',
    ]);
}

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
});

/* ---------------- the service ---------------- */

test('recording a file attributes its bytes to the company', function () {
    $company = storageCompany();

    Storage::disk('local')->put('lead-documents/1/contract.pdf', str_repeat('x', 2048));

    CompanyStorage::record(
        $company->id,
        'lead-documents/1/contract.pdf',
        CompanyFile::CATEGORY_LEAD_DOCUMENT,
    );

    expect($company->fresh()->storage_used_bytes)->toBe(2048)
        ->and(CompanyFile::where('company_id', $company->id)->count())->toBe(1);
});

test('recording the same path twice does not double count', function () {
    $company = storageCompany();

    Storage::disk('local')->put('whatsapp/images/1/a.jpg', str_repeat('x', 1000));

    // A retried webhook must not inflate the total.
    CompanyStorage::record($company->id, 'whatsapp/images/1/a.jpg', CompanyFile::CATEGORY_WHATSAPP_MEDIA);
    CompanyStorage::record($company->id, 'whatsapp/images/1/a.jpg', CompanyFile::CATEGORY_WHATSAPP_MEDIA);

    expect($company->fresh()->storage_used_bytes)->toBe(1000)
        ->and(CompanyFile::where('company_id', $company->id)->count())->toBe(1);
});

test('re-recording a rewritten path adjusts by the difference', function () {
    $company = storageCompany();

    Storage::disk('local')->put('profile_pics/1/me.jpg', str_repeat('x', 500));
    CompanyStorage::record($company->id, 'profile_pics/1/me.jpg', CompanyFile::CATEGORY_PROFILE_PICTURE);

    Storage::disk('local')->put('profile_pics/1/me.jpg', str_repeat('x', 1500));
    CompanyStorage::record($company->id, 'profile_pics/1/me.jpg', CompanyFile::CATEGORY_PROFILE_PICTURE);

    expect($company->fresh()->storage_used_bytes)->toBe(1500);
});

test('forgetting a file gives the bytes back', function () {
    $company = storageCompany();

    Storage::disk('local')->put('sales/1/2/quote.pdf', str_repeat('x', 4096));
    CompanyStorage::record($company->id, 'sales/1/2/quote.pdf', CompanyFile::CATEGORY_DEAL_DOCUMENT);

    expect($company->fresh()->storage_used_bytes)->toBe(4096);

    CompanyStorage::forget('sales/1/2/quote.pdf');

    expect($company->fresh()->storage_used_bytes)->toBe(0)
        ->and(CompanyFile::where('company_id', $company->id)->count())->toBe(0);
});

test('forgetting an unknown path is harmless', function () {
    $company = storageCompany();

    Storage::disk('local')->put('a.bin', str_repeat('x', 100));
    CompanyStorage::record($company->id, 'a.bin', CompanyFile::CATEGORY_CHAT_ATTACHMENT);

    // Deletes are instrumented defensively, so this happens routinely.
    CompanyStorage::forget('never-recorded.bin');
    CompanyStorage::forget('a.bin');
    CompanyStorage::forget('a.bin');

    expect($company->fresh()->storage_used_bytes)->toBe(0);
});

test('the counter never goes negative', function () {
    $company = storageCompany();

    CompanyStorage::forget('nothing-here.bin');

    expect($company->fresh()->storage_used_bytes)->toBe(0);
});

test('accounting failures never break the caller', function () {
    // A file that is not on the disk records as zero bytes rather than
    // throwing — the upload it describes must still succeed.
    $company = storageCompany();

    $file = CompanyStorage::record($company->id, 'missing/file.pdf', CompanyFile::CATEGORY_LEAD_DOCUMENT);

    expect($file)->not->toBeNull()
        ->and($file->size_bytes)->toBe(0)
        ->and($company->fresh()->storage_used_bytes)->toBe(0);
});

test('a null company is ignored rather than orphaning a row', function () {
    CompanyStorage::record(null, 'orphan.bin', CompanyFile::CATEGORY_WHATSAPP_MEDIA);

    expect(CompanyFile::count())->toBe(0);
});

/* ---------------- isolation and reporting ---------------- */

test('usage is isolated per company', function () {
    $mine   = storageCompany('Mine');
    $theirs = storageCompany('Theirs');

    Storage::disk('local')->put('mine.bin', str_repeat('x', 3000));
    Storage::disk('local')->put('theirs.bin', str_repeat('x', 7000));

    CompanyStorage::record($mine->id, 'mine.bin', CompanyFile::CATEGORY_LEAD_DOCUMENT);
    CompanyStorage::record($theirs->id, 'theirs.bin', CompanyFile::CATEGORY_LEAD_DOCUMENT);

    expect($mine->fresh()->storage_used_bytes)->toBe(3000)
        ->and($theirs->fresh()->storage_used_bytes)->toBe(7000);
});

test('usage breaks down by category, largest first', function () {
    $company = storageCompany();

    Storage::disk('local')->put('doc.pdf', str_repeat('x', 1000));
    Storage::disk('local')->put('media.jpg', str_repeat('x', 9000));

    CompanyStorage::record($company->id, 'doc.pdf', CompanyFile::CATEGORY_LEAD_DOCUMENT);
    CompanyStorage::record($company->id, 'media.jpg', CompanyFile::CATEGORY_WHATSAPP_MEDIA);

    $usage = CompanyStorage::usage($company->fresh());

    expect($usage['bytes'])->toBe(10000)
        ->and($usage['files'])->toBe(2)
        ->and($usage['by_category'][0]['category'])->toBe(CompanyFile::CATEGORY_WHATSAPP_MEDIA)
        ->and($usage['by_category'][0]['bytes'])->toBe(9000);
});

test('the counter can be rebuilt from the ledger after drift', function () {
    $company = storageCompany();

    Storage::disk('local')->put('a.bin', str_repeat('x', 2500));
    CompanyStorage::record($company->id, 'a.bin', CompanyFile::CATEGORY_CHAT_ATTACHMENT);

    // Simulate drift: a write path added without a record() call, or a crash.
    $company->forceFill(['storage_used_bytes' => 999999])->saveQuietly();

    CompanyStorage::recalculate($company);

    expect($company->fresh()->storage_used_bytes)->toBe(2500);
});

test('deleting a company removes its ledger rows', function () {
    $company = storageCompany();

    Storage::disk('local')->put('a.bin', str_repeat('x', 100));
    CompanyStorage::record($company->id, 'a.bin', CompanyFile::CATEGORY_LEAD_DOCUMENT);

    $company->forceDelete();

    expect(CompanyFile::where('company_id', $company->id)->exists())->toBeFalse();
});

/* ---------------- the real upload paths ---------------- */

test('uploading a lead document is counted', function () {
    $company = storageCompany();
    $user    = storageUser($company);

    $lead = Lead::create([
        'company_id' => $company->id,
        'first_name' => 'Ana',
        'email'      => 'ana-' . uniqid() . '@example.test',
    ]);

    $this->actingAs($user)->postJson('/api/lead-documents', [
        'lead_id' => $lead->id,
        'title'   => 'Contract',
        // createWithContent, not create(): the latter writes a zero-byte file
        // and only fakes getSize(), while accounting measures the disk.
        'file'    => UploadedFile::fake()->createWithContent('contract.pdf', str_repeat('x', 12000)),
    ])->assertCreated();

    $file = CompanyFile::where('company_id', $company->id)->first();

    expect($file)->not->toBeNull()
        ->and($file->category)->toBe(CompanyFile::CATEGORY_LEAD_DOCUMENT)
        ->and($company->fresh()->storage_used_bytes)->toBeGreaterThan(0);
});

test('uploading and then deleting a deal document leaves the counter at zero', function () {
    $company = storageCompany();
    $user    = storageUser($company);

    $lead = Lead::create([
        'company_id' => $company->id,
        'first_name' => 'Bruno',
        'email'      => 'bruno-' . uniqid() . '@example.test',
    ]);

    // sales.funnel_id / stage_id are NOT NULL, so the deal needs a pipeline.
    $funnel = \App\Models\Funnel::create([
        'company_id' => $company->id,
        'name'       => 'Deals',
        'type'       => 'deal',
        'is_active'  => true,
        'created_by' => $user->id,
    ]);

    $stage = \App\Models\Stage::create([
        'funnel_id' => $funnel->id,
        'name'      => 'New',
        'type'      => 'entry',
        'order'     => 1,
        'is_active' => true,
    ]);

    $sale = Sale::create([
        'company_id'    => $company->id,
        'lead_id'       => $lead->id,
        'user_id'       => $user->id,
        'funnel_id'     => $funnel->id,
        'stage_id'      => $stage->id,
        'name'          => 'Renewal',
        'status'        => 'in_pipeline',
        'pricing_model' => 'product',
    ]);

    $doc = $this->actingAs($user)->postJson("/api/sales/{$sale->id}/docs", [
        'file' => UploadedFile::fake()->createWithContent('quote.pdf', str_repeat('x', 8000)),
    ])->assertCreated()->json();

    expect($company->fresh()->storage_used_bytes)->toBeGreaterThan(0)
        ->and(CompanyFile::where('category', CompanyFile::CATEGORY_DEAL_DOCUMENT)->count())->toBe(1);

    $this->actingAs($user)->deleteJson("/api/sales/docs/{$doc['id']}")->assertOk();

    expect($company->fresh()->storage_used_bytes)->toBe(0)
        ->and(CompanyFile::where('category', CompanyFile::CATEGORY_DEAL_DOCUMENT)->count())->toBe(0);
});

test('plan usage reports real storage instead of a hardcoded zero', function () {
    $company = storageCompany();
    $user    = storageUser($company);

    Storage::disk('local')->put('big.bin', str_repeat('x', 5000));
    CompanyStorage::record($company->id, 'big.bin', CompanyFile::CATEGORY_WHATSAPP_MEDIA);

    $res = $this->actingAs($user)->getJson('/api/metrics/plan-usage');

    $res->assertOk();
    expect($res->json('storage.used_bytes'))->toBe(5000)
        ->and($res->json('storage.limit'))->toBe(5)
        ->and($res->json('storage.by_category.0.category'))->toBe(CompanyFile::CATEGORY_WHATSAPP_MEDIA);
});
