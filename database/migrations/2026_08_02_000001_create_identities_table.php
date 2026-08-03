<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The person behind the accounts.
 *
 * A user row is an account *inside one company* — that is what makes every
 * `company_id` filter in the codebase correct. An identity is the human who
 * may hold several of them, and is what a password is checked against.
 *
 * Additive: nothing reads this table until the auth flow is switched over.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('identities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identities');
    }
};
