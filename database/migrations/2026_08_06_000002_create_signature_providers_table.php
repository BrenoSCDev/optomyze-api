<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DIGITAL SIGNATURE — PROVIDER ACCOUNTS
 *
 * One row per (company, vendor): the credentials a tenant signs with. Each
 * company brings its own vendor account, so the billing and the legal audit
 * trail stay theirs rather than Optomyze's.
 *
 * Deliberately generic. `provider` is a key from config/signatures.php, and
 * credentials live in a single encrypted JSON column rather than as named
 * columns, because vendors do not agree on what a credential is: Clicksign
 * issues one access token, DocuSign wants an integration key plus an account
 * id plus a JWT keypair. Naming columns after Clicksign's shape would mean a
 * migration for every future vendor, which is exactly what this module is
 * built to avoid.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('signature_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Driver key from config/signatures.php — 'clicksign', later
            // 'docusign', 'adobe_sign', …
            $table->string('provider', 40);

            // Optional label, for companies holding more than one vendor.
            $table->string('name')->nullable();

            // Which of the driver's environments to talk to: most vendors
            // publish a sandbox alongside production.
            $table->string('environment', 20)->default('sandbox');

            // Encrypted JSON. Shape is the driver's business, not the schema's.
            $table->text('credentials')->nullable();

            // Our half of the webhook URL. The vendor only ever sees this
            // UUID, so a leaked callback URL can be rotated without touching
            // credentials, and it is what resolves the tenant on delivery.
            $table->uuid('webhook_callback_id')->unique();

            // The vendor's id for the callback we registered, so it can be
            // updated or deleted later.
            $table->string('webhook_external_id')->nullable();

            // Signing key for verifying deliveries. Encrypted: it is a
            // credential, and most vendors show it exactly once.
            $table->text('webhook_secret')->nullable();

            $table->string('webhook_status', 20)->default('pending');
            $table->string('connection_status', 20)->default('not_configured');

            $table->timestamp('last_tested_at')->nullable();
            $table->text('last_error')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // A company holds at most one account per vendor.
            $table->unique(['company_id', 'provider']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_providers');
    }
};
