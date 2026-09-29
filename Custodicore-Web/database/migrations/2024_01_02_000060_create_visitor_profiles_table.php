<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// section 3.6 — Module 1.3 Visitor Management (mobile app)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitor_profiles', function (Blueprint $table) {
            $table->id('visitor_id');
            $table->foreignId('account_id')->unique()->constrained('accounts', 'account_id')->cascadeOnDelete();
            $table->string('full_name', 150);
            $table->date('date_of_birth');
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->string('address', 255)->nullable();
            $table->string('contact_number', 20);
            $table->string('emergency_contact_name', 150)->nullable();
            $table->string('emergency_contact_number', 20)->nullable();
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->foreignId('verified_by')->nullable()->constrained('staff_profiles', 'staff_id')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_profiles');
    }
};
