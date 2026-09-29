<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// section 5.3 — one row per visitor -> PDL visit assignment. The "max two
// visits per week" rule is enforced in the app layer, not here (see
// CustodiCore Database Design doc, section 8).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_requests', function (Blueprint $table) {
            $table->id('visit_request_id');
            $table->foreignId('visitor_id')->constrained('visitor_profiles', 'visitor_id')->cascadeOnDelete();
            $table->foreignId('pdl_id')->constrained('pdl_profiles', 'pdl_id')->cascadeOnDelete();
            $table->foreignId('relationship_id')->constrained('visitor_pdl_relationships', 'relationship_id');
            $table->foreignId('schedule_id')->constrained('visit_schedules', 'schedule_id');
            $table->enum('status', [
                'assigned', 'pending_confirmation', 'confirmed', 'declined',
                'cancelled', 'completed', 'no_show',
            ])->default('pending_confirmation');
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('confirmation_deadline')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['visitor_id', 'status']);
            $table->index(['pdl_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_requests');
    }
};
