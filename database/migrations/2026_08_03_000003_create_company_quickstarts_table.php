<?php

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quickstart checklist — one row per company.
 *
 * Which steps exist is defined in code (CompanyQuickstart::STEPS), not in the
 * database: the checklist is a fixed onboarding script, and a row per step
 * would mean a data migration every time the script changes. The row only
 * records what this company has *done* — the completed keys and whether the
 * checklist was dismissed.
 *
 * Unknown keys in `completed_steps` are ignored on read, so retiring a step is
 * a code change alone.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('company_quickstarts', function (Blueprint $table) {
            $table->id();
            // One checklist per company; the unique constraint is what makes
            // firstOrCreate safe under concurrent requests.
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();

            $table->json('completed_steps')->nullable();

            // Dismissing hides the checklist from the menu. Kept as a timestamp
            // rather than a flag so we can tell how long onboarding took.
            $table->timestamp('dismissed_at')->nullable();

            $table->timestamps();
        });

        // Every company that already exists gets one too, so the feature is not
        // limited to companies registered from today on. New companies are
        // covered by the `created` hook on the Company model.
        $now = now();

        Company::withTrashed()
            ->select('id')
            ->orderBy('id')
            ->chunk(500, function ($companies) use ($now) {
                DB::table('company_quickstarts')->insert(
                    $companies->map(fn ($company) => [
                        'company_id'      => $company->id,
                        'completed_steps' => json_encode([]),
                        'created_at'      => $now,
                        'updated_at'      => $now,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_quickstarts');
    }
};
