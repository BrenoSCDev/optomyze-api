<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DIGITAL SIGNATURE — REQUESTS, DOCUMENTS, SIGNERS
 *
 * The core triple, modelled on what every vendor agrees about rather than on
 * any one vendor's nouns:
 *
 *   signature_requests   one signing process (Clicksign calls it an envelope,
 *                        DocuSign also an envelope, Dropbox Sign a signature
 *                        request) attached to a Lead or a Deal
 *   signature_documents  the files inside it
 *   signature_signers    the people asked to sign
 *
 * Two uniqueness rules carry real weight, because both the send path and the
 * webhook path retry:
 *
 *   - (provider, provider_request_id) unique: a retried send that already
 *     created the remote request cannot produce a second local row pointing
 *     at it.
 *
 *   - (signature_request_id, source_checksum) unique: re-uploading the same
 *     bytes into one request is refused at the database, so a job retried
 *     after a timeout cannot silently attach the contract twice.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('signature_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // The account this was sent through. Nulled rather than cascaded
            // when an account is removed: a signed contract's history must
            // outlive the credentials that produced it.
            $table->foreignId('signature_provider_id')->nullable()
                ->constrained('signature_providers')->nullOnDelete();

            // Denormalised from the account so the row still says which vendor
            // it came from after that account is gone.
            $table->string('provider', 40);

            // Who sent it.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // What it is about — a Lead or a Sale (deal). Polymorphic, the
            // same way notes already attach to both.
            $table->nullableMorphs('signable');

            $table->string('provider_request_id')->nullable();

            $table->string('title');
            $table->string('subject')->nullable();
            $table->text('message')->nullable();

            // App\Services\Signatures\Support\SignatureStatus
            $table->string('status', 20)->default('draft');

            $table->string('locale', 10)->default('pt-BR');
            $table->boolean('auto_close')->default(true);

            // Days between vendor reminders; null means "do not remind".
            $table->unsignedTinyInteger('reminder_interval')->nullable()->default(3);

            $table->boolean('block_after_refusal')->default(false);
            $table->timestamp('deadline_at')->nullable();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('refused_at')->nullable();

            // Why the last send attempt failed, surfaced in the UI.
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);

            // Driver-specific extras that do not deserve a column — kept out
            // of the shared schema on purpose.
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'provider_request_id'], 'signature_requests_provider_remote_unique');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'created_at']);
        });

        Schema::create('signature_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signature_request_id')->constrained()->cascadeOnDelete();

            $table->string('provider_document_id')->nullable();

            $table->string('filename');
            $table->string('mime', 150)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('disk', 40)->default('local');

            // The file we uploaded to the vendor.
            $table->string('source_path', 500);

            // SHA-256 of those bytes. Doubles as the duplicate guard below.
            $table->char('source_checksum', 64);

            // The finished file, once pulled back and stored by us. We keep a
            // copy rather than the vendor's link because vendor links are
            // undocumented as to expiry, and a contract has to stay readable.
            $table->string('signed_path', 500)->nullable();
            $table->unsignedBigInteger('signed_size_bytes')->nullable();

            // What the vendor told us to fetch, kept verbatim for debugging.
            $table->text('signed_file_url')->nullable();

            // pending | stored | unavailable
            $table->string('download_status', 20)->default('pending');
            $table->text('download_error')->nullable();
            $table->timestamp('downloaded_at')->nullable();

            $table->unsignedSmallInteger('position')->default(1);

            $table->timestamps();

            $table->unique(['signature_request_id', 'source_checksum'], 'signature_documents_request_checksum_unique');
            $table->index('provider_document_id');
        });

        Schema::create('signature_signers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signature_request_id')->constrained()->cascadeOnDelete();

            // Where this person lives in the CRM, when they are known to it.
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('provider_signer_id')->nullable();

            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();

            // Government id (CPF in Brazil). Generic name because the next
            // vendor will be asked for a different country's equivalent.
            $table->string('document_number', 40)->nullable();
            $table->date('birthdate')->nullable();

            // Signing order. Same number signs in parallel; higher numbers
            // wait for lower ones.
            $table->unsignedTinyInteger('signing_group')->default(1);

            $table->boolean('refusable')->default(true);

            // Identity checks this signer must clear, as driver-declared keys.
            $table->json('auth_methods')->nullable();

            // How the vendor should reach them (channel per notification kind).
            $table->json('communication')->nullable();

            // App\Services\Signatures\Support\SignerStatus
            $table->string('status', 20)->default('pending');

            $table->timestamp('notified_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('refused_at')->nullable();
            $table->text('refusal_reason')->nullable();

            $table->text('sign_url')->nullable();

            $table->timestamps();

            $table->index(['signature_request_id', 'status']);
            $table->index('provider_signer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_signers');
        Schema::dropIfExists('signature_documents');
        Schema::dropIfExists('signature_requests');
    }
};
