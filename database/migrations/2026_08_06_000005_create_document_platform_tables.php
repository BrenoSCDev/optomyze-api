<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DOCUMENT AUTOMATION PLATFORM
 *
 * A contract is not a file. It is a business record — with a type, a number, a
 * lifecycle, an owner, a value, a term and a version history — that happens to
 * produce a file. The signature is one stage of its life, not its identity.
 *
 * Everything here is named `document`, never `contract`, because a contract is
 * one *row* of this platform. Proposals, quotes, NDAs and onboarding forms are
 * other rows of the same tables; they need a `document_types` entry and a
 * template, not a schema change.
 *
 * Three rules the shape encodes:
 *
 *   - **Versions are immutable.** A generated contract is evidence. Regenerating
 *     writes a new `document_versions` row and repoints `current_version_id`;
 *     it never overwrites, because the version somebody signed has to remain
 *     byte-identical to what they saw.
 *
 *   - **The template body is snapshotted into the version.** Editing a template
 *     next year must not retroactively change what last year's contract says.
 *     Copying the body at generation time gives that immutability without
 *     needing a whole template-versioning system.
 *
 *   - **A Deal has many documents.** Proposal, main contract, maintenance
 *     addendum and NDA all hang off one opportunity, so the link is a nullable
 *     FK on the document, not a unique one.
 */
return new class extends Migration {
    public function up(): void
    {
        /*
         * Per-company taxonomy, shaped like the other lookup tables
         * (LostReason, ClosingReason) but carrying policy: whether this kind of
         * document needs a signature at all, and whether it needs approval
         * before it can be sent.
         */
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('key', 60);
            $table->string('label');
            $table->text('description')->nullable();

            // Broad family: contract | proposal | quote | nda | other.
            // Drives the number prefix and which module screens list it.
            $table->string('category', 20)->default('contract');

            // Seeded rows a company may deactivate but not delete.
            $table->boolean('is_system')->default(false);

            $table->boolean('requires_signature')->default(true);
            $table->boolean('requires_approval')->default(false);

            $table->unsignedBigInteger('default_template_id')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->unique(['company_id', 'key']);
            $table->index(['company_id', 'category', 'is_active']);
        });

        /*
         * Reusable bodies. Legal writes and publishes them; sales can only
         * generate from published ones.
         */
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->nullable()
                ->constrained('document_types')->nullOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // The authored body, in the platform's template language.
            $table->longText('body_html');

            // Optional page furniture, rendered by the PDF driver.
            $table->text('header_html')->nullable();
            $table->text('footer_html')->nullable();

            // Variables this template actually references, extracted on save.
            // Lets the UI warn "this template needs a Deal" before generation.
            $table->json('variables_schema')->nullable();

            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['company_id', 'is_published', 'is_active']);
        });

        /*
         * Company-defined static values — CNPJ, legal address, standard
         * warranty period. CRM-derived variables ({{lead.name}}, {{deal.total}})
         * are declared in code by VariableRegistry, not stored here.
         */
        Schema::create('document_variables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('key', 80);
            $table->string('label');
            $table->text('value')->nullable();
            $table->string('group', 40)->default('custom');
            $table->text('description')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });

        /*
         * Human-facing numbers (CTR-2026-0001), allocated under a row lock.
         * A sequence table rather than MAX(number)+1: two people creating a
         * contract in the same second must not be given the same number, and a
         * contract number is quoted in correspondence and courtrooms.
         */
        Schema::create('document_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('prefix', 10);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'prefix', 'year']);
        });

        /* The entity itself. */
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->foreignId('document_type_id')->nullable()
                ->constrained('document_types')->nullOnDelete();

            // Unique per company, never reused.
            $table->string('number', 40);
            $table->string('title');

            // App\Services\Documents\Support\DocumentStatus
            $table->string('status', 30)->default('draft');

            /*
             * Where it sits in the CRM. All nullable and all indexed: a
             * contract usually comes from a deal, but an NDA may hang off a
             * lead with no opportunity, and a master agreement off the customer
             * company alone.
             */
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_company_id')->nullable()
                ->constrained('lead_companies')->nullOnDelete();

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('document_template_id')->nullable()
                ->constrained('document_templates')->nullOnDelete();

            // Set after the first generation; FK added below once the table exists.
            $table->unsignedBigInteger('current_version_id')->nullable();

            // What the agreement is worth, independent of the deal — a contract
            // is often signed for a different figure than the opportunity.
            $table->decimal('value', 15, 2)->nullable();
            $table->string('currency', 3)->nullable();

            /*
             * Term and renewal. Columns land now, behaviour is phase 2 — so
             * that phase is listeners and a scheduled command, not another
             * migration against a table that by then holds real contracts.
             */
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('renewal_date')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->string('renewal_frequency', 20)->nullable();
            $table->unsignedSmallInteger('notice_period_days')->nullable();

            /* Approval. Same reasoning as the term columns. */
            $table->string('approval_status', 20)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'archived_at']);
            $table->index(['sale_id', 'status']);
            $table->index(['lead_id', 'status']);
            $table->index('renewal_date');
        });

        /* Immutable render history. */
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('version_number');

            // The template body as it stood at generation time. This is what
            // makes editing a template safe for contracts already generated.
            $table->longText('source_html');

            // The body after interpolation — what the signer actually read.
            $table->longText('rendered_html');

            // Every variable value frozen at generation, so a version can be
            // explained years later even if the lead has since been edited.
            $table->json('variables')->nullable();

            $table->string('pdf_disk', 40)->default('local');
            $table->string('pdf_path', 500)->nullable();
            $table->unsignedBigInteger('pdf_size_bytes')->nullable();

            // SHA-256 of the PDF bytes — the integrity claim for an audit.
            $table->char('checksum', 64)->nullable();

            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['document_id', 'version_number']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('current_version_id')
                ->references('id')->on('document_versions')->nullOnDelete();
        });

        Schema::table('document_types', function (Blueprint $table) {
            $table->foreign('default_template_id')
                ->references('id')->on('document_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropForeign(['default_template_id']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });

        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_number_sequences');
        Schema::dropIfExists('document_variables');
        Schema::dropIfExists('document_templates');
        Schema::dropIfExists('document_types');
    }
};
