<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_product_fields', function (Blueprint $table) {
            $table->foreignId('group_id')
                ->nullable()
                ->after('type')
                ->constrained('custom_product_field_groups')
                ->nullOnDelete();
            $table->string('icon')->nullable()->after('group_id'); // react-icons identifier
            $table->integer('display_order')->default(0)->after('icon');
        });
    }

    public function down(): void
    {
        Schema::table('custom_product_fields', function (Blueprint $table) {
            $table->dropForeign(['group_id']);
            $table->dropColumn(['group_id', 'icon', 'display_order']);
        });
    }
};
