<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long a visitor flag applies. NULL = until a Record Officer resolves it
 * (the original behaviour). Once expires_at passes, the flag no longer counts
 * as active — see VisitorFlag::isActive() / VisitorProfile::activeFlags().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_flags', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_flags', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
