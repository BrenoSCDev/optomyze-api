<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
    }

    public function down(): void
    {
        Schema::create('permissions', function ($table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('group', 50);
            $table->string('description');
            $table->timestamp('created_at')->useCurrent();

            $table->index('group');
        });

        Schema::create('role_permissions', function ($table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('role', 50);
            $table->string('permission_key', 100);

            $table->unique(['company_id', 'role', 'permission_key']);
            $table->index(['company_id', 'role']);

            $table->foreign('permission_key')
                ->references('key')
                ->on('permissions')
                ->cascadeOnDelete();
        });
    }
};
