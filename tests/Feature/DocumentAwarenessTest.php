<?php

use App\Events\DocumentLiveUpdate;
use App\Events\DocumentSigned;
use App\Events\DocumentTransactionNotification;
use App\Models\Document;
use App\Models\NotificationSetting;
use App\Models\SignatureAuditLog;
use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Services\Documents\DocumentHistory;
use App\Services\Documents\Support\DocumentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * How a document makes itself known: the live board, the bell, and the history.
 *
 * These three exist for one reason — almost nothing that moves a contract is
 * done by somebody looking at it. The counterparty signs, refuses or lets a
 * deadline pass, and the change lands on a queue worker. Everything here is a
 * test that the CRM notices.
 *
 * Reuses the helpers in DocumentEngineTest (docCompany, docUser, docType,
 * docTemplate, …); Pest loads both files into the same suite.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

/* ============================================================
 | Live board
 ============================================================ */

test('creating a document broadcasts it to the company board', function () {
    Event::fake([DocumentLiveUpdate::class]);

    $company = docCompany();
    $user    = docUser($company);

    $document = Document::create([
        'company_id'       => $company->id,
        'document_type_id' => docType($company)->id,
        'number'           => 'CTR-2026-0001',
        'title'            => 'Live board test',
        'owner_id'         => $user->id,
    ]);

    Event::assertDispatched(
        DocumentLiveUpdate::class,
        fn (DocumentLiveUpdate $event) => $event->broadcastWith()['action'] === 'created'
            && $event->broadcastWith()['document_id'] === $document->id
    );
});

test('a status change broadcasts both statuses so the card can animate', function () {
    $company = docCompany();
    $user    = docUser($company);

    $document = Document::create([
        'company_id'       => $company->id,
        'document_type_id' => docType($company)->id,
        'number'           => 'CTR-2026-0002',
        'title'            => 'Status move',
        'owner_id'         => $user->id,
    ]);

    // Faked only now, so the create above does not pollute the assertion.
    Event::fake([DocumentLiveUpdate::class]);

    $document->forceFill(['status' => DocumentStatus::WAITING_SIGNATURE])->save();

    Event::assertDispatched(DocumentLiveUpdate::class, function (DocumentLiveUpdate $event) {
        $payload = $event->broadcastWith();

        return $payload['action'] === 'status_changed'
            && $payload['old_status'] === DocumentStatus::DRAFT
            && $payload['new_status'] === DocumentStatus::WAITING_SIGNATURE;
    });
});

test('an edit that leaves the status alone is not reported as a move', function () {
    $company = docCompany();

    $document = Document::create([
        'company_id'       => $company->id,
        'document_type_id' => docType($company)->id,
        'number'           => 'CTR-2026-0003',
        'title'            => 'Before',
        'owner_id'         => docUser($company)->id,
    ]);

    Event::fake([DocumentLiveUpdate::class]);

    $document->forceFill(['title' => 'After'])->save();

    Event::assertDispatched(
        DocumentLiveUpdate::class,
        fn (DocumentLiveUpdate $event) => $event->broadcastWith()['action'] === 'updated'
            && $event->broadcastWith()['old_status'] === null
    );
});

test('the broadcast carries the same card shape the board renders', function () {
    $company = docCompany();

    $document = Document::create([
        'company_id'       => $company->id,
        'document_type_id' => docType($company)->id,
        'number'           => 'CTR-2026-0004',
        'title'            => 'Shape',
        'owner_id'         => docUser($company)->id,
    ]);

    $broadcast = (new DocumentLiveUpdate('updated', $document))->broadcastWith();

    // A field present in one and not the other would blank a card on a live
    // update, which is the whole failure mode toCardArray() exists to prevent.
    expect($broadcast['document'])->toEqual($document->toCardArray());
});

test('the board channel is private to the company', function () {
    $company = docCompany();

    $document = Document::create([
        'company_id'       => $company->id,
        'document_type_id' => docType($company)->id,
        'number'           => 'CTR-2026-0005',
        'title'            => 'Channel',
        'owner_id'         => docUser($company)->id,
    ]);

    expect((new DocumentLiveUpdate('updated', $document))->broadcastOn()->name)
        ->toBe('private-company.' . $company->id);
});

/* ============================================================
 | Notifications
 ============================================================ */

test('a signature notifies the document owner in app', function () {
    Event::fake([DocumentTransactionNotification::class]);

    $company = docCompany();
    $owner   = docUser($company, 'agent');

    NotificationSetting::create([
        'company_id'         => $company->id,
        'channels'           => ['in_app' => true, 'email' => false, 'whatsapp' => false],
        'default_recipients' => ['admin' => false, 'deal_owner' => false, 'lead_owner' => false, 'team_manager' => false],
        'events'             => [],
        'quiet_hours'        => ['enabled' => false],
    ]);

    $document = Document::create([
        'company_id'       => $company->id,
        'document_type_id' => docType($company)->id,
        'number'           => 'CTR-2026-0010',
        'title'            => 'Signed contract',
        'owner_id'         => $owner->id,
        'status'           => DocumentStatus::SIGNED,
    ]);

    event(new DocumentSigned($document));

    Event::assertDispatched(
        DocumentTransactionNotification::class,
        fn (DocumentTransactionNotification $event) => $event->eventKey === 'document_signed'
            && $event->document->id === $document->id
    );
});

test('document events fall back to config defaults when settings predate them', function () {
    // The regression this guards: a company row written before documents
    // existed has an `events` map with no document_* keys. Reading enabled
    // from that map alone silently drops every contract notification.
    $company = docCompany();

    NotificationSetting::create([
        'company_id'         => $company->id,
        'channels'           => ['in_app' => true, 'email' => false, 'whatsapp' => false],
        'default_recipients' => [],
        'events'             => ['lead_created' => ['enabled' => true]],
        'quiet_hours'        => ['enabled' => false],
    ]);

    expect(config('notification_defaults.events.document_signed.enabled'))->toBeTrue()
        ->and(config('notification_defaults.events.document_rejected.enabled'))->toBeTrue()
        ->and(config('notification_defaults.events.document_expired.enabled'))->toBeTrue()
        // Off on purpose: the owner is the one who pressed Send.
        ->and(config('notification_defaults.events.document_sent.enabled'))->toBeFalse();
});

test('each document listener is registered exactly once', function () {
    // Regression, fixed 2026-08-07. Laravel's DiscoverEvents scans
    // app/Listeners and auto-registers every `handle*` method with a typed
    // event parameter. The listeners were ALSO registered explicitly in
    // AppServiceProvider, so each ran twice: two timeline entries per
    // signature and two notifications per outcome.
    //
    // Keeping the methods named on*() is what prevents it. This test fails if
    // anybody renames one back to handle*().
    $dispatcher = app('events');

    $property = (new ReflectionClass($dispatcher))->getProperty('listeners');
    $property->setAccessible(true);

    $events = [
        \App\Events\DocumentSigned::class,
        \App\Events\DocumentRejected::class,
        \App\Events\DocumentExpired::class,
        \App\Events\DocumentSent::class,
    ];

    foreach ($events as $event) {
        $registered = $property->getValue($dispatcher)[$event] ?? [];

        $names = array_map(function ($listener) {
            if (is_array($listener)) {
                return (is_object($listener[0]) ? get_class($listener[0]) : $listener[0]) . '::' . $listener[1];
            }

            return is_string($listener) ? $listener : 'closure';
        }, $registered);

        // Only listeners of ours; the framework may attach its own.
        $ours = array_values(array_filter(
            $names,
            fn ($name) => str_contains($name, 'App\\Listeners\\Documents')
        ));

        expect($ours)->toEqual(array_unique($ours), "duplicate listener registered for {$event}");
    }
});

test('a single signature produces a single notification', function () {
    $company = docCompany();

    NotificationSetting::create([
        'company_id'         => $company->id,
        'channels'           => ['in_app' => true, 'email' => false, 'whatsapp' => false],
        'default_recipients' => [],
        'events'             => [],
        'quiet_hours'        => ['enabled' => false],
    ]);

    $document = Document::create([
        'company_id'       => $company->id,
        'document_type_id' => docType($company)->id,
        'number'           => 'CTR-2026-0013',
        'title'            => 'Once',
        'owner_id'         => docUser($company)->id,
        'status'           => DocumentStatus::SIGNED,
    ]);

    $broadcasts = 0;
    app('events')->listen(
        DocumentTransactionNotification::class,
        function () use (&$broadcasts) { $broadcasts++; }
    );

    event(new DocumentSigned($document));

    expect($broadcasts)->toBe(1);
});

test('the notification payload names the document and its outcome', function () {
    $company = docCompany();

    $document = Document::create([
        'company_id'       => $company->id,
        'document_type_id' => docType($company)->id,
        'number'           => 'CTR-2026-0011',
        'title'            => 'Refused contract',
        'owner_id'         => docUser($company)->id,
        'status'           => DocumentStatus::REJECTED,
    ]);

    $payload = (new DocumentTransactionNotification($document, 'document_rejected'))->broadcastWith();

    expect($payload['type'])->toBe('document_rejected')
        ->and($payload['message'])->toContain('CTR-2026-0011')
        ->and($payload['priority'])->toBe('high')
        ->and($payload['document']['id'])->toBe($document->id);
});

test('a disabled event notifies nobody', function () {
    Event::fake([DocumentTransactionNotification::class]);

    $company = docCompany();

    NotificationSetting::create([
        'company_id'         => $company->id,
        'channels'           => ['in_app' => true, 'email' => false, 'whatsapp' => false],
        'default_recipients' => [],
        'events'             => ['document_signed' => ['enabled' => false]],
        'quiet_hours'        => ['enabled' => false],
    ]);

    $document = Document::create([
        'company_id'       => $company->id,
        'document_type_id' => docType($company)->id,
        'number'           => 'CTR-2026-0012',
        'title'            => 'Muted',
        'owner_id'         => docUser($company)->id,
        'status'           => DocumentStatus::SIGNED,
    ]);

    event(new DocumentSigned($document));

    Event::assertNotDispatched(DocumentTransactionNotification::class);
});

/* ============================================================
 | Transaction history
 ============================================================ */

test('the history merges milestones, versions and provider events on one clock', function () {
    $company = docCompany();
    $user    = docUser($company);

    $document = Document::create([
        'company_id'       => $company->id,
        'document_type_id' => docType($company)->id,
        'number'           => 'CTR-2026-0020',
        'title'            => 'History',
        'owner_id'         => $user->id,
        'created_by'       => $user->id,
        'status'           => DocumentStatus::SIGNED,
        'sent_at'          => now()->subHours(2),
        'signed_at'        => now()->subHour(),
    ]);

    $request = SignatureRequest::create([
        'company_id'  => $company->id,
        'document_id' => $document->id,
        'provider'    => 'fake',
        'title'       => 'History',
        'status'      => 'completed',
    ]);

    $signer = SignatureSigner::create([
        'company_id'           => $company->id,
        'signature_request_id' => $request->id,
        'name'                 => 'Jordan Reyes',
        'email'                => 'jordan@example.test',
        'status'               => 'signed',
    ]);

    SignatureEvent::create([
        'company_id'           => $company->id,
        'signature_request_id' => $request->id,
        'signature_signer_id'  => $signer->id,
        'provider'             => 'fake',
        'provider_event_name'  => 'sign',
        'type'                 => 'signed',
        'dedupe_key'           => 'evt-1',
        'occurred_at'          => now()->subMinutes(90),
    ]);

    $entries = app(DocumentHistory::class)->for($document);
    $titles  = array_column($entries, 'title');

    expect($titles)->toContain('Document created')
        ->and($titles)->toContain('Sent for signature')
        ->and($titles)->toContain('Fully signed')
        ->and($titles)->toContain('Signed by Jordan Reyes');

    // Newest first — a history read backwards is worse than none.
    //
    // Compared as instants, not as strings: entries carry their own UTC
    // offset, so "17:45-03:00" sorts after "17:15-04:00" lexically while
    // being half an hour *earlier* in real time.
    $instants = array_map(
        fn ($entry) => \Carbon\Carbon::parse($entry['at'])->getTimestamp(),
        $entries
    );
    $descending = $instants;
    rsort($descending);

    expect($instants)->toEqual($descending);
});

test('api traffic is excluded from the history unless it is asked for', function () {
    $company = docCompany();

    $document = Document::create([
        'company_id'       => $company->id,
        'document_type_id' => docType($company)->id,
        'number'           => 'CTR-2026-0021',
        'title'            => 'Technical',
        'owner_id'         => docUser($company)->id,
    ]);

    $request = SignatureRequest::create([
        'company_id'  => $company->id,
        'document_id' => $document->id,
        'provider'    => 'fake',
        'title'       => 'Technical',
        'status'      => 'sent',
    ]);

    SignatureAuditLog::create([
        'company_id'           => $company->id,
        'signature_request_id' => $request->id,
        'provider'             => 'fake',
        'direction'            => 'outbound',
        'action'               => 'create_envelope',
        'method'               => 'POST',
        'endpoint'             => 'https://example.test/api/v3/envelopes',
        'status_code'          => 201,
        'success'              => true,
    ]);

    $history = app(DocumentHistory::class);

    $business = $history->for($document, includeTechnical: false);
    expect(collect($business)->pluck('channel')->unique()->all())->not->toContain('technical');

    $technical = $history->for($document, includeTechnical: true);
    expect(collect($technical)->firstWhere('channel', 'technical'))
        ->not->toBeNull()
        ->and(collect($technical)->firstWhere('channel', 'technical')['detail'])
        // The path, not the full URL — a timeline of absolute URLs is unreadable.
        ->toContain('/api/v3/envelopes');
});

test('the history of another company\'s document is not reachable', function () {
    $ours   = docCompany('Ours');
    $theirs = docCompany('Theirs');

    $document = Document::create([
        'company_id'       => $theirs->id,
        'document_type_id' => docType($theirs)->id,
        'number'           => 'CTR-2026-0030',
        'title'            => 'Not yours',
        'owner_id'         => docUser($theirs)->id,
    ]);

    $this->actingAs(docUser($ours))
        ->getJson("/api/documents/{$document->id}/history")
        ->assertStatus(404);
});

test('a viewer who cannot edit is refused the technical channel', function () {
    $company = docCompany();
    $owner   = docUser($company, 'agent');
    $other   = docUser($company, 'agent');

    $document = Document::create([
        'company_id'       => $company->id,
        'document_type_id' => docType($company)->id,
        'number'           => 'CTR-2026-0031',
        'title'            => 'Gated',
        'owner_id'         => $owner->id,
    ]);

    // Endpoints and error text are operator detail, not something every
    // colleague with read access should see.
    $this->actingAs($other)
        ->getJson("/api/documents/{$document->id}/history?technical=1")
        ->assertOk()
        ->assertJsonPath('technical_included', false);
});
