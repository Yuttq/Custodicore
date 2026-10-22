<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Walk-in details the Front Desk records (App\Services\WalkInService):
 *   - visitor_profiles.intended_pdl_number: the PDL ID number the visitor
 *     gives, unverified like intended_pdl_name, to help Records match the PDL;
 *   - walk_in_assessments: one row per walk-in arrival, holding the purpose
 *     of the visit (Front Desk) and the Record Officer's assessment. The
 *     decision gates booking: a visit is booked only once it is `approved`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->string('intended_pdl_number', 50)->nullable()->after('intended_pdl_name');
        });

        Schema::create('walk_in_assessments', function (Blueprint $table) {
            $table->id('assessment_id');
            $table->foreignId('visitor_id')->constrained('visitor_profiles', 'visitor_id')->cascadeOnDelete();
            $table->string('visit_purpose', 150);
            $table->enum('pdl_verification', ['verified', 'not_found'])->nullable();
            $table->enum('visitor_eligibility', ['verified', 'for_review'])->nullable();
            $table->enum('authorized_visitor', ['yes', 'no', 'for_review'])->nullable();
            $table->enum('decision', ['approved', 'pending', 'declined'])->default('pending');
            $table->enum('walk_in_reason', ['no_prior_booking', 'other'])->default('no_prior_booking');
            $table->text('remarks')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('staff_profiles', 'staff_id')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('visit_request_id')->nullable()->constrained('visit_requests', 'visit_request_id')->nullOnDelete();
            $table->timestamps();

            $table->index(['visitor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('walk_in_assessments');

        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->dropColumn('intended_pdl_number');
        });
    }
};
