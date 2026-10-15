<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Relationship documents uploaded with the registration.
 *
 * At registration there is no visitor↔PDL relationship yet (staff create it
 * after checking the PDL), so those files are stored with the visitor only
 * (relationship_id NULL). When staff link the PDL, the files whose
 * requirement the new relationship's checklist asks for are attached to it
 * (VisitorPdlRelationship::attachRegistrationDocuments), and the Record
 * Officer reviews them there as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relationship_documents', function (Blueprint $table) {
            $table->foreignId('visitor_id')->nullable()->after('relationship_id')
                ->constrained('visitor_profiles', 'visitor_id')->cascadeOnDelete();
            $table->unsignedBigInteger('relationship_id')->nullable()->change();
            $table->index(['visitor_id', 'requirement_key']);
        });

        // Existing rows all belong to a relationship; record whose they are.
        DB::table('relationship_documents')->whereNull('visitor_id')->update([
            'visitor_id' => DB::raw('(SELECT r.visitor_id FROM visitor_pdl_relationships r WHERE r.relationship_id = relationship_documents.relationship_id)'),
        ]);
    }

    public function down(): void
    {
        // Registration files that were never attached have no relationship to keep them.
        DB::table('relationship_documents')->whereNull('relationship_id')->delete();

        Schema::table('relationship_documents', function (Blueprint $table) {
            $table->dropIndex(['visitor_id', 'requirement_key']);
            $table->dropConstrainedForeignId('visitor_id');
            $table->unsignedBigInteger('relationship_id')->nullable(false)->change();
        });
    }
};
