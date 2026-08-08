<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIGNATURES BECOME A STAGE OF A DOCUMENT
 *
 * The signature module was built with `signature_requests` as the top-level
 * entity, attached polymorphically to a lead or a deal. That made the signature
 * the thing and the agreement an attachment, which is backwards: the agreement
 * is the business record, and collecting signatures is one stage of its life.
 *
 * So the parent is now the document. `document_id` replaces the polymorphic
 * link, and `document_version_id` records *which* rendered version went out —
 * without it, a contract regenerated after sending could not be told apart from
 * the one the signer actually saw, which is the whole point of versioning.
 *
 * Dropping `signable_type`/`signable_id` rather than keeping them is safe: this
 * work is uncommitted, unreleased and has no rows anywhere. Retaining a second
 * path from a signature request to a lead would immediately let the two
 * disagree.
 *
 * `signature_signers.ip_address` is added because a signature's evidentiary
 * value rests on it. It is populated only when a provider actually reports it —
 * Clicksign does not document an IP field on its `sign` payload, so it will
 * often be null, and null is the honest answer.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->foreignId('document_id')->nullable()->after('company_id')
                ->constrained('documents')->cascadeOnDelete();

            $table->foreignId('document_version_id')->nullable()->after('document_id')
                ->constrained('document_versions')->nullOnDelete();

            $table->index(['document_id', 'status']);
        });

        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropMorphs('signable');
        });

        Schema::table('signature_signers', function (Blueprint $table) {
            // Captured from the provider when it reports one; never inferred.
            $table->string('ip_address', 45)->nullable()->after('sign_url');
        });
    }

    public function down(): void
    {
        Schema::table('signature_signers', function (Blueprint $table) {
            $table->dropColumn('ip_address');
        });

        Schema::table('signature_requests', function (Blueprint $table) {
            $table->nullableMorphs('signable');
        });

        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropForeign(['document_version_id']);
            $table->dropForeign(['document_id']);
            $table->dropIndex(['document_id', 'status']);
            $table->dropColumn(['document_id', 'document_version_id']);
        });
    }
};
