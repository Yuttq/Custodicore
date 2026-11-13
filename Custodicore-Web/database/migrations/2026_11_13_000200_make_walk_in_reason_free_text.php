<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Front Desk types the reason for a walk-in instead of choosing
 * "No prior booking" / "Other". Saved reasons become their label text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('walk_in_assessments', function (Blueprint $table) {
            $table->string('walk_in_reason', 255)->nullable()->default(null)->change();
        });

        DB::table('walk_in_assessments')->where('walk_in_reason', 'no_prior_booking')->update(['walk_in_reason' => 'No prior booking']);
        DB::table('walk_in_assessments')->where('walk_in_reason', 'other')->update(['walk_in_reason' => 'Other']);
        // Undecided arrivals have no reason yet.
        DB::table('walk_in_assessments')->where('decision', 'pending')->update(['walk_in_reason' => null]);
    }

    public function down(): void
    {
        DB::table('walk_in_assessments')->whereNull('walk_in_reason')->orWhere('walk_in_reason', 'No prior booking')->update(['walk_in_reason' => 'no_prior_booking']);
        DB::table('walk_in_assessments')->where('walk_in_reason', '!=', 'no_prior_booking')->update(['walk_in_reason' => 'other']);

        Schema::table('walk_in_assessments', function (Blueprint $table) {
            $table->enum('walk_in_reason', ['no_prior_booking', 'other'])->default('no_prior_booking')->change();
        });
    }
};
