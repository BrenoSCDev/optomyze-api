<?php

use App\Models\Company;
use Illuminate\Support\Str;
use App\Models\Funnel;
use App\Models\Lead;
use App\Models\Stage;
use App\Models\User;
use App\Models\Widget;
use App\Services\Widgets\WidgetTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function widgetCompany(): Company
{
    return Company::create([
        'name'      => 'Widget Co',
        'email'     => 'widgets@example.test',
        'is_active' => true,
    ]);
}

/** A saved form widget, going through the real config validation. */
function makeFormWidget(Company $company, array $mapping): Widget
{
    $type = WidgetTypeRegistry::resolve('form');
    $config = $type->defaultConfig();
    $config['mapping'] = $mapping;

    return Widget::create([
        'company_id'      => $company->id,
        'type'            => 'form',
        'name'            => 'Contact form',
        'status'          => 'active',
        'allowed_domains' => ['example.com'],
        'config'          => $type->normalizeConfig($config, $company->id),
        'public_id'       => Str::uuid()->toString(),
    ]);
}

/** funnels.created_by and stages.created_by are both NOT NULL. */
function makeFunnelWithStage(Company $company): array
{
    $owner = User::factory()->create([
        'company_id' => $company->id,
        'email'      => "owner-{$company->id}@example.test",
        'role'       => 'admin',
    ]);

    $funnel = Funnel::create([
        'company_id' => $company->id,
        'name'       => 'Inbound',
        'type'       => 'contact',
        'is_active'  => true,
        'created_by' => $owner->id,
    ]);

    // stages carry no company_id — they are scoped through their funnel.
    $stage = Stage::create([
        'funnel_id' => $funnel->id,
        'name'      => 'New',
        'type'      => 'entry',
        'order'     => 1,
        'is_active' => true,
    ]);

    return [$funnel, $stage];
}

function submitTo(Widget $widget, array $fields)
{
    return test()
        ->withHeader('Origin', 'https://example.com')
        ->postJson("/api/public/widgets/{$widget->public_id}/submit", [
            'data' => $fields,
            // Clears the min_submit_seconds gate; a real visitor takes longer
            // than the default 2s to fill the form in.
            'elapsed_seconds' => 30,
        ]);
}

test('a form widget saves with no funnel at all', function () {
    $company = widgetCompany();

    $widget = makeFormWidget($company, ['funnel_id' => null, 'stage_id' => null]);

    expect($widget->config['mapping']['funnel_id'])->toBeNull()
        ->and($widget->config['mapping']['stage_id'])->toBeNull();
});

test('a submission with no funnel creates an unfiled lead', function () {
    $company = widgetCompany();
    $widget = makeFormWidget($company, ['funnel_id' => null, 'stage_id' => null]);

    $res = submitTo($widget, [
        'name'  => 'Nina Unfiled',
        'email' => 'nina@example.com',
        'phone' => '5511999990000',
    ]);
    $res->assertSuccessful();

    $lead = Lead::where('company_id', $company->id)->firstOrFail();

    expect($lead->funnel_id)->toBeNull()
        ->and($lead->stage_id)->toBeNull()
        ->and($lead->first_name)->toBe('Nina')
        ->and($lead->source_platform)->toBe(Lead::PLATFORM_WEBSITE)
        ->and($lead->source_type)->toBe(Lead::SOURCE_WEB_FORM);
});

test('a funnel is still honoured when one is chosen', function () {
    $company = widgetCompany();
    [$funnel, $stage] = makeFunnelWithStage($company);

    $widget = makeFormWidget($company, ['funnel_id' => $funnel->id, 'stage_id' => $stage->id]);

    submitTo($widget, ['name' => 'Filed Lead', 'email' => 'filed@example.com'])->assertSuccessful();

    $lead = Lead::where('company_id', $company->id)->firstOrFail();

    expect($lead->funnel_id)->toBe($funnel->id)
        ->and($lead->stage_id)->toBe($stage->id);
});

test('a stage without its funnel is rejected', function () {
    $company = widgetCompany();
    [, $stage] = makeFunnelWithStage($company);

    // A stage alone says nothing about which funnel the lead belongs to.
    expect(fn () => makeFormWidget($company, ['funnel_id' => null, 'stage_id' => $stage->id]))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

test('another company\'s funnel is still refused', function () {
    $company = widgetCompany();
    $other = Company::create(['name' => 'Other', 'email' => 'other@example.test', 'is_active' => true]);
    [$theirFunnel, $theirStage] = makeFunnelWithStage($other);

    expect(fn () => makeFormWidget($company, ['funnel_id' => $theirFunnel->id, 'stage_id' => $theirStage->id]))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

test('an unfiled lead is reachable from contacts', function () {
    $company = widgetCompany();
    $widget = makeFormWidget($company, ['funnel_id' => null, 'stage_id' => null]);

    submitTo($widget, ['name' => 'Nina Unfiled', 'email' => 'nina@example.com'])->assertSuccessful();

    // The point of allowing no funnel: the lead still has to be findable.
    $admin = User::factory()->create(['company_id' => $company->id, 'role' => 'admin', 'email' => 'admin@example.test']);
    $res = test()->actingAs($admin)->getJson('/api/contacts');

    $res->assertSuccessful();
    expect(collect($res->json('data'))->pluck('email'))->toContain('nina@example.com');
});
