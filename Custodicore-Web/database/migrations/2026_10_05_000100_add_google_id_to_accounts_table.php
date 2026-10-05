<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the Google account's stable subject identifier (the verified ID
 * token's `sub` claim) for visitor Google Sign-In.
 *
 * Nullable: password-only accounts (every existing account) have none.
 * Unique: one Google identity can belong to at most one account. Multiple
 * NULLs are allowed by both MySQL and SQLite unique indexes.
 *
 * Keyed on `sub`, never on email — Google emails can change, `sub` cannot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('google_id', 255)->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn('google_id');
        });
    }
};
