<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STORAGE ACCOUNTING
 *
 * `companies.storage_limit_gb` has existed since the first migration, but
 * nothing ever measured usage: the plan-usage endpoint read
 * `$company->storage_use`, a column that does not exist, so every company has
 * always reported 0 bytes used against its limit.
 *
 * Two pieces fix that:
 *
 *   - `company_files` is a ledger: one row per file the CRM stores, with the
 *     company that owns it, its size and what kind of file it is. A ledger
 *     rather than a bare counter because "you are near your limit" is useless
 *     without "and it is mostly WhatsApp media" — and because a counter that
 *     drifts has no way back, whereas a ledger can always be re-summed.
 *
 *   - `companies.storage_used_bytes` is the denormalised total, kept in step
 *     with the ledger. The plan-usage endpoint is read on every dashboard
 *     load and should not sum a table of every file to answer.
 *
 * Bytes, not gigabytes: the limit is expressed in GB but a 40 KB avatar has to
 * be representable, and integer bytes avoid the rounding drift that would come
 * from accumulating fractions.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('company_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Which Laravel disk the file lives on. Most are 'local' (private);
            // lead company logos are on 'public'.
            $table->string('disk', 40)->default('local');

            // Long enough for nested per-company paths. Unique with the disk so
            // recording the same file twice cannot double-count it.
            $table->string('path', 500);

            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('mime', 150)->nullable();

            // What the file is for: whatsapp_media, lead_document, deal_document…
            // This is what turns the total into a breakdown.
            $table->string('category', 40)->index();

            // Optional link back to the record that owns the file, so deleting
            // that record can find its files without knowing their paths.
            $table->nullableMorphs('owner');

            $table->timestamps();

            $table->unique(['disk', 'path'], 'company_files_disk_path_unique');
            $table->index(['company_id', 'category']);
        });

        Schema::table('companies', function (Blueprint $table) {
            // Denormalised sum of company_files.size_bytes. Rebuildable at any
            // time with `php artisan storage:recalculate`.
            $table->unsignedBigInteger('storage_used_bytes')->default(0)->after('storage_limit_gb');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('storage_used_bytes');
        });

        Schema::dropIfExists('company_files');
    }
};
