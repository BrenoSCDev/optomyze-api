<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->unsignedInteger('api_tokens_limit')->nullable()->after('messages_limit');
            $table->unsignedBigInteger('api_requests_limit')->nullable()->after('api_tokens_limit');
            $table->unsignedBigInteger('api_requests_count')->default(0)->after('api_requests_limit');
            $table->timestamp('api_requests_reset_at')->nullable()->after('api_requests_count');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'api_tokens_limit',
                'api_requests_limit',
                'api_requests_count',
                'api_requests_reset_at',
            ]);
        });
    }
};
