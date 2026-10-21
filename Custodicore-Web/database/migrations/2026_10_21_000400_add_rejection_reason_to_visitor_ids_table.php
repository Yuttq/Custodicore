<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The visitor-facing reason staff give when rejecting a government ID
 * (VisitorController::rejectId). Cleared when the ID is verified. Separate
 * from `reason`, which Front Desk uses for why a new ID was recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_ids', function (Blueprint $table) {
            $table->string('rejection_reason', 500)->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_ids', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });
    }
};
