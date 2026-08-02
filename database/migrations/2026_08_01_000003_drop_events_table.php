<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Appointments are now the CRM's only scheduling entity. Events were a
 * parallel, lead-less calendar record that duplicated the concept, so the
 * table goes with the rest of the module.
 *
 * `down()` recreates the schema but NOT the rows — this drop is destructive
 * and was confirmed as such.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('events');
    }

    public function down(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type')->nullable();
            $table->timestamp('start_at');
            $table->timestamp('end_at')->nullable();
            $table->boolean('all_day')->default(false);
            $table->boolean('reminder')->default(true);
            $table->timestamps();
        });
    }
};
