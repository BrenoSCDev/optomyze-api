<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entry_requirements', function (Blueprint $table) {
            $table->id();

            // Relationship
            $table->foreignId('stage_id')
                ->constrained()
                ->cascadeOnDelete();

            // Entry requirements (all booleans)
            $table->boolean('require_assigned_owner')->default(false);
            $table->boolean('require_contact_email')->default(false);
            $table->boolean('require_phone_number')->default(false);
            $table->boolean('require_at_least_one_product')->default(false);
            $table->boolean('require_deal_value')->default(false);
            $table->boolean('require_recent_activity')->default(false);

            $table->timestamps();

            // One-to-one guarantee
            $table->unique('stage_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_requirements');
    }
};
