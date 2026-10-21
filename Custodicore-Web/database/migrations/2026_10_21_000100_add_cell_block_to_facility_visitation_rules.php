<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dorm visiting schedules. A visitation rule (weekly day + hours + visitor
 * capacity) now belongs either to a PDL classification (the original
 * facility-wide days) or to one dorm (cell_block_id). A PDL whose dorm has
 * its own rules follows those; otherwise the classification rules still
 * apply — see FacilityVisitationRule::rulesForPdl().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facility_visitation_rules', function (Blueprint $table) {
            $table->foreignId('cell_block_id')->nullable()->after('rule_id')
                ->constrained('cell_blocks', 'cell_block_id');
            $table->enum('pdl_classification', ['drug_related', 'non_drug_related'])->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('facility_visitation_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cell_block_id');
        });
    }
};
