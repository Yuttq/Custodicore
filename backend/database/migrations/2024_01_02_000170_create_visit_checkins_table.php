<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// section 5.5 — covers both the QR scan and the manual ID-surrender procedure
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_checkins', function (Blueprint $table) {
            $table->id('checkin_id');
            $table->foreignId('visit_request_id')->unique()->constrained('visit_requests', 'visit_request_id')->cascadeOnDelete();
            $table->foreignId('qr_code_id')->constrained('qr_codes', 'qr_code_id');
            $table->string('id_surrendered_type', 50)->nullable();
            $table->timestamp('id_surrender_time')->nullable();
            $table->timestamp('check_in_time')->nullable();
            $table->foreignId('check_in_officer_id')->nullable()->constrained('staff_profiles', 'staff_id')->nullOnDelete();
            $table->timestamp('check_out_time')->nullable();
            $table->foreignId('check_out_officer_id')->nullable()->constrained('staff_profiles', 'staff_id')->nullOnDelete();
            $table->timestamp('id_returned_time')->nullable();
            $table->enum('verification_method', ['qr_scan', 'manual_override'])->default('qr_scan');
            $table->string('override_reason', 255)->nullable();
            $table->enum('status', ['awaiting_arrival', 'checked_in', 'checked_out', 'no_show'])->default('awaiting_arrival');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_checkins');
    }
};
