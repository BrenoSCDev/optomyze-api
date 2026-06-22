<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('closing_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('name');
            $table->text('message');

            $table->timestamps();
        });

        Schema::create('closing_message_closing_reason', function (Blueprint $table) {
            $table->id();

            $table->foreignId('closing_message_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('closing_reason_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['closing_message_id', 'closing_reason_id'], 'closing_message_closing_reason_unique');
        });

        // Replaced by the closing_messages many-to-many relationship above —
        // no existing data to migrate (column was unused at this point).
        Schema::table('closing_reasons', function (Blueprint $table) {
            $table->dropColumn('automatic_closing_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('closing_reasons', function (Blueprint $table) {
            $table->text('automatic_closing_message')->nullable()->after('description');
        });

        Schema::dropIfExists('closing_message_closing_reason');
        Schema::dropIfExists('closing_messages');
    }
};
