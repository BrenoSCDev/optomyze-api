<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DIGITAL SIGNATURE — EVENTS AND AUDIT LOG
 *
 * Two tables that look similar and are not:
 *
 *   signature_events      the *domain* timeline. One row per meaningful thing
 *                         that happened to a request — signed, refused,
 *                         completed. This is what the UI renders and what the
 *                         business cares about.
 *
 *   signature_audit_logs  the *wire* record. One row per HTTP call we made and
 *                         per delivery we received, including the ones that
 *                         failed, were rejected as unauthentic, or were
 *                         duplicates. This is what you read at 2am.
 *
 * `dedupe_key` is what makes webhook processing idempotent. Vendors redeliver
 * — on our 500, on their retry policy, sometimes just twice — and a signature
 * event applied twice would double-post a timeline and could re-trigger a
 * download. The unique index means a repeat delivery loses a race it never
 * knew it was in, and the applier treats that as success.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('signature_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->foreignId('signature_request_id')->nullable()
                ->constrained()->cascadeOnDelete();

            $table->foreignId('signature_signer_id')->nullable()
                ->constrained('signature_signers')->nullOnDelete();

            $table->string('provider', 40);

            // The vendor's own name for it, preserved verbatim.
            $table->string('provider_event_name', 80);

            // App\Services\Signatures\Support\SignatureEventType — what the
            // rest of the app switches on.
            $table->string('type', 40);

            // The vendor's event id when it sends one.
            $table->string('external_id')->nullable();

            // SHA-256 over the event's identifying content. Unique per vendor,
            // so redelivery is a no-op. Derived from event content only —
            // never from receipt time, which would differ on every retry and
            // make the index useless.
            $table->char('dedupe_key', 64);

            $table->timestamp('occurred_at')->nullable();

            // The delivery body as received.
            $table->json('payload')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'dedupe_key'], 'signature_events_provider_dedupe_unique');
            $table->index(['signature_request_id', 'occurred_at']);
            $table->index(['company_id', 'type']);
        });

        Schema::create('signature_audit_logs', function (Blueprint $table) {
            $table->id();

            // Nullable: a delivery can arrive for a callback id that matches
            // nothing, and that attempt is exactly what we want recorded.
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('signature_provider_id')->nullable()
                ->constrained('signature_providers')->nullOnDelete();
            $table->foreignId('signature_request_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('provider', 40);

            // outbound = we called the vendor; inbound = the vendor called us.
            $table->string('direction', 10);

            // Domain name for the call: create_request, upload_document,
            // notify_signer, webhook_received, download_signed_document…
            $table->string('action', 60);

            $table->string('method', 10)->nullable();
            $table->string('endpoint', 500)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('success')->default(false);

            // Both stored with credentials redacted and large bodies (base64
            // file content, most of all) elided — see SignatureAuditLogger.
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();

            $table->text('error')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->index(['signature_request_id', 'created_at']);
            $table->index(['provider', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_audit_logs');
        Schema::dropIfExists('signature_events');
    }
};
