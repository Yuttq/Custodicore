<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// section 3.8 — many-to-many visitor <-> PDL, with the relationship data
// Module 1.6's "Relationship Verification to PDL" step checks
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_pdl_relationships', function (Blueprint $table) {
            $table->id('relationship_id');
            $table->foreignId('visitor_id')->constrained('visitor_profiles', 'visitor_id')->cascadeOnDelete();
            $table->foreignId('pdl_id')->constrained('pdl_profiles', 'pdl_id')->cascadeOnDelete();
            $table->enum('relationship_type', [
                'immediate_family', 'legal_counsel', 'verified_guardian',
                'approved_relative', 'unknown_or_other',
            ]);
            $table->enum('priority_tier', ['high_priority', 'requires_verification']);
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->string('supporting_document_path', 255)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('staff_profiles', 'staff_id')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['visitor_id', 'pdl_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_pdl_relationships');
    }
};
