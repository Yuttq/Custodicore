<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5B: an approved visitor's request to add another PDL.
 *
 * The visitor knows only the PDL's name, so a request stays separate from
 * visitor_pdl_relationships (whose pdl_id is required) until the Record
 * Officer identifies the PDL and links it.
 *
 * - open_visitor_id equals visitor_id while the request is open and is NULL
 *   otherwise. Its unique index lets the database itself allow only one open
 *   request per visitor (a unique index accepts any number of NULLs).
 * - relationship_documents.pdl_link_request_id marks the files sent with a
 *   request. Such a file has no relationship_id until the request is linked,
 *   and must never be treated as a registration document (see
 *   RelationshipDocument::scopeRegistrationUploads).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdl_link_requests', function (Blueprint $table) {
            $table->id('request_id');
            $table->foreignId('visitor_id')->constrained('visitor_profiles', 'visitor_id')->cascadeOnDelete();
            // Same length as visitor_profiles.intended_pdl_name.
            $table->string('pdl_name', 150);
            // Same type as visitor_pdl_relationships.relationship_type.
            $table->string('relationship_type', 30);
            $table->boolean('has_children_together')->nullable();
            $table->enum('status', ['open', 'linked', 'rejected', 'cancelled'])->default('open');
            $table->unsignedBigInteger('open_visitor_id')->nullable()->unique();
            $table->foreignId('relationship_id')->nullable()
                ->constrained('visitor_pdl_relationships', 'relationship_id')->nullOnDelete();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('staff_profiles', 'staff_id')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['visitor_id', 'status']);
        });

        Schema::table('relationship_documents', function (Blueprint $table) {
            // Kept after the request is linked, as a record of where the file
            // came from. nullOnDelete: request rows are never deleted by the
            // app (only their status changes); a visitor deletion removes the
            // visitor's documents through relationship_documents.visitor_id.
            $table->foreignId('pdl_link_request_id')->nullable()->after('visitor_id')
                ->constrained('pdl_link_requests', 'request_id')->nullOnDelete();
            $table->index('pdl_link_request_id');
        });
    }

    public function down(): void
    {
        // A request's files that were never linked have nothing else to keep them.
        \Illuminate\Support\Facades\DB::table('relationship_documents')
            ->whereNull('relationship_id')
            ->whereNotNull('pdl_link_request_id')
            ->delete();

        Schema::table('relationship_documents', function (Blueprint $table) {
            // MySQL backs the foreign key with this index, so the key goes first.
            $table->dropForeign(['pdl_link_request_id']);
            $table->dropIndex(['pdl_link_request_id']);
            $table->dropColumn('pdl_link_request_id');
        });

        Schema::dropIfExists('pdl_link_requests');
    }
};
