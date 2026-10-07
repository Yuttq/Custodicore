<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-use, expiring email verification links (see
 * App\Services\Auth\EmailVerificationService). Only the SHA-256 hash of the
 * token is stored — the plain token exists only in the emailed link.
 *
 * `email` is the address the link was sent to: if the account's email has
 * changed since, the link no longer verifies anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_verification_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts', 'account_id')->cascadeOnDelete();
            $table->string('email', 100);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_tokens');
    }
};
