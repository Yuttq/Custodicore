<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// section 3.7 — government IDs accepted per Module 1.6
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_ids', function (Blueprint $table) {
            $table->id('visitor_id_doc_id');
            $table->foreignId('visitor_id')->constrained('visitor_profiles', 'visitor_id')->cascadeOnDelete();
            $table->enum('id_type', [
                'national_id', 'drivers_license', 'passport',
                'voters_id', 'philhealth_id', 'umid',
            ]);
            $table->string('id_number', 50);
            $table->string('file_path', 255);
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->foreignId('verified_by')->nullable()->constrained('staff_profiles', 'staff_id')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('uploaded_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_ids');
    }
};
