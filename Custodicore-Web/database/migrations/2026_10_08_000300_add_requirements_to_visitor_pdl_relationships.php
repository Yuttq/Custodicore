<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BJMP visitor requirements (see App\Services\RelationshipRequirements).
 *
 * - relationship_type becomes a plain string so it can hold the specific
 *   types (spouse, live_in_partner, parent, ...). Two old generic values map
 *   cleanly; immediate_family / unknown_or_other are kept as "legacy" and the
 *   Record Officer must reclassify them before the relationship can be
 *   verified.
 * - has_children_together: live-in partners need a child's PSA birth
 *   certificate if they have children, a witness statement if not.
 * - officer_confirmations: things the officer confirms rather than a
 *   document (e.g. "no immediate family available", minor's accompanying
 *   guardian). {key: {by, at, note}}
 * - relationship_documents: one row per uploaded requirement file, keeping
 *   history; the newest row per requirement_key is the one that counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_pdl_relationships', function (Blueprint $table) {
            $table->string('relationship_type', 30)->change();
            $table->boolean('has_children_together')->nullable()->after('priority_tier');
            $table->json('officer_confirmations')->nullable()->after('has_children_together');
        });

        DB::table('visitor_pdl_relationships')->where('relationship_type', 'verified_guardian')->update(['relationship_type' => 'legal_guardian']);
        DB::table('visitor_pdl_relationships')->where('relationship_type', 'approved_relative')->update(['relationship_type' => 'extended_relative']);

        Schema::create('relationship_documents', function (Blueprint $table) {
            $table->id('document_id');
            $table->foreignId('relationship_id')->constrained('visitor_pdl_relationships', 'relationship_id')->cascadeOnDelete();
            $table->string('requirement_key', 40);
            $table->string('file_path', 255);
            $table->string('original_name', 255)->nullable();
            $table->enum('status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('staff_profiles', 'staff_id')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('uploaded_at')->useCurrent();

            $table->index(['relationship_id', 'requirement_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relationship_documents');

        DB::table('visitor_pdl_relationships')->where('relationship_type', 'legal_guardian')->update(['relationship_type' => 'verified_guardian']);
        DB::table('visitor_pdl_relationships')->where('relationship_type', 'extended_relative')->update(['relationship_type' => 'approved_relative']);
        DB::table('visitor_pdl_relationships')
            ->whereNotIn('relationship_type', ['immediate_family', 'legal_counsel', 'verified_guardian', 'approved_relative'])
            ->update(['relationship_type' => 'unknown_or_other']);

        Schema::table('visitor_pdl_relationships', function (Blueprint $table) {
            $table->dropColumn(['has_children_together', 'officer_confirmations']);
            $table->enum('relationship_type', [
                'immediate_family', 'legal_counsel', 'verified_guardian',
                'approved_relative', 'unknown_or_other',
            ])->change();
        });
    }
};
