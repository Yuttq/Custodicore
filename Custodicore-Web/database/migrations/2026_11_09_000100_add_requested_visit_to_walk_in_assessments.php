<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Walk-in arrivals now carry what the Front Desk chose at the desk
 * (App\Services\WalkInService):
 *   - pdl_id: the PDL selected from the PDL search;
 *   - requested_date / requested_start_times: the date and session(s), from
 *     the sessions Records opened, that Records' approval books.
 * Nullable: arrivals recorded before this change have none; the Front Desk
 * chooses them on the walk-in page before Records can approve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('walk_in_assessments', function (Blueprint $table) {
            $table->foreignId('pdl_id')->nullable()->after('relationship_type')
                ->constrained('pdl_profiles', 'pdl_id')->nullOnDelete();
            $table->date('requested_date')->nullable()->after('pdl_id');
            $table->json('requested_start_times')->nullable()->after('requested_date');
        });
    }

    public function down(): void
    {
        Schema::table('walk_in_assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pdl_id');
            $table->dropColumn(['requested_date', 'requested_start_times']);
        });
    }
};
