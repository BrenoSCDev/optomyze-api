<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('role', 50);
            $table->string('permission_key', 100);

            $table->unique(['role', 'permission_key']);
            $table->index('role');

            $table->foreign('permission_key')
                ->references('key')
                ->on('permissions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
