<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generalises the single-vendor `openai_integrations` table into a
 * provider-keyed one, so Gemini and Claude are rows rather than new tables.
 *
 * This creates-and-copies rather than renaming in place: the old table carries
 * a UNIQUE on company_id that a foreign key depends on, and unpicking that
 * (drop FK -> drop index -> add composite -> re-add FK) is far more fragile
 * across MySQL versions than moving a handful of rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_provider_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Driver key from config/llm.php.
            $table->string('provider')->default('openai');

            $table->string('name')->nullable();
            $table->text('api_key')->nullable();

            // OpenAI's optional org scoping. Kept as a column rather than a
            // json blob because it is the only extra credential any current
            // driver takes; a second one would justify generalising.
            $table->string('organization_id')->nullable();

            $table->string('connection_status')->default('not_configured');
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One account per vendor per company.
            $table->unique(['company_id', 'provider']);
        });

        if (Schema::hasTable('openai_integrations')) {
            DB::table('openai_integrations')->orderBy('id')->chunkById(200, function ($rows) {
                $now = now();

                DB::table('llm_provider_accounts')->insert(
                    collect($rows)->map(fn ($row) => [
                        'company_id'        => $row->company_id,
                        'provider'          => 'openai',
                        'name'              => $row->name ?? null,
                        // Copied ciphertext-as-is: the cast encrypts with
                        // APP_KEY, which is unchanged, so the value stays
                        // readable without a decrypt/re-encrypt round trip.
                        'api_key'           => $row->api_key,
                        'organization_id'   => $row->organization_id,
                        'connection_status' => $row->connection_status,
                        'last_tested_at'    => $row->last_tested_at,
                        'is_active'         => $row->is_active,
                        'created_at'        => $row->created_at ?? $now,
                        'updated_at'        => $row->updated_at ?? $now,
                    ])->all()
                );
            });

            Schema::drop('openai_integrations');
        }
    }

    public function down(): void
    {
        Schema::create('openai_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('name')->default('ChatGPT (OpenAI)');
            $table->text('api_key')->nullable();
            $table->string('organization_id')->nullable();
            $table->string('connection_status')->default('not_configured');
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Only the OpenAI rows have anywhere to go back to; Gemini and Claude
        // accounts are dropped with the table.
        DB::table('llm_provider_accounts')
            ->where('provider', 'openai')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                $now = now();

                DB::table('openai_integrations')->insert(
                    collect($rows)->map(fn ($row) => [
                        'company_id'        => $row->company_id,
                        'name'              => $row->name ?? 'ChatGPT (OpenAI)',
                        'api_key'           => $row->api_key,
                        'organization_id'   => $row->organization_id,
                        'connection_status' => $row->connection_status,
                        'last_tested_at'    => $row->last_tested_at,
                        'is_active'         => $row->is_active,
                        'created_at'        => $row->created_at ?? $now,
                        'updated_at'        => $row->updated_at ?? $now,
                    ])->all()
                );
            });

        Schema::dropIfExists('llm_provider_accounts');
    }
};
