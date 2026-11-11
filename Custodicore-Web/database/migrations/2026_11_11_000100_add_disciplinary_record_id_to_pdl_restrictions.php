<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A restriction can name the disciplinary incident that led to it.
 * A disciplinary record shows "Led to restriction" only when a restriction
 * is linked to it, so the label always matches what actually happened
 * (replaces the old, unconnected "triggers_restriction" checkbox).
 * Nullable: most restrictions (quarantine, court order, ...) have no incident.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdl_restrictions', function (Blueprint $table) {
            $table->foreignId('disciplinary_record_id')->nullable()->after('pdl_id')
                ->constrained('pdl_disciplinary_records', 'disciplinary_record_id')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pdl_restrictions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('disciplinary_record_id');
        });
    }
};
