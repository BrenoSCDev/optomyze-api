<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('lead_transactions');
        Schema::dropIfExists('sale_transactions');
    }

    public function down(): void
    {
        Schema::create('lead_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->onDelete('cascade');
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('type');
            $table->string('action');
            $table->text('description');
            $table->string('source')->default('system');
            $table->timestamps();
            $table->index(['lead_id', 'created_at']);
            $table->index(['company_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('sale_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sale_id')->nullable();
            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('set null');
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('type');
            $table->string('action');
            $table->text('description');
            $table->string('source')->default('system');
            $table->timestamps();
            $table->index(['sale_id', 'created_at']);
            $table->index(['company_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }
};
