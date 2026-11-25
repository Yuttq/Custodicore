<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * released_at — when the PDL's custody status became "released" (set by the
 * Pdl model; cleared on re-admission). PDL Records counts "Released YTD" from
 * it instead of updated_at, which any later edit would move.
 *
 * Backfill: PDLs released before this column existed get the date of their
 * last "Custody status: … → released" audit entry. Those with no such entry
 * stay empty (unknown) and are not counted in a year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdl_profiles', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable()->after('custody_status');
        });

        DB::table('pdl_profiles')->where('custody_status', 'released')->orderBy('pdl_id')->pluck('pdl_id')
            ->each(function ($pdlId) {
                $releasedAt = DB::table('audit_logs')
                    ->where('record_type', 'pdl_profiles')
                    ->where('record_id', $pdlId)
                    ->where('description', 'like', '%→ released%')
                    ->max('created_at');

                if ($releasedAt) {
                    DB::table('pdl_profiles')->where('pdl_id', $pdlId)->update(['released_at' => $releasedAt]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('pdl_profiles', function (Blueprint $table) {
            $table->dropColumn('released_at');
        });
    }
};
