<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// section 4.1 — Module 1.2 PDL Management. `classification` drives the
// visiting-day rule in Module 1.4 (drug_related -> Thu/Sat, non_drug_related -> Fri/Sun).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdl_profiles', function (Blueprint $table) {
            $table->id('pdl_id');
            $table->string('pdl_number', 30)->unique();
            $table->string('full_name', 150);
            $table->string('alias', 150)->nullable();
            $table->date('date_of_birth');
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->enum('classification', ['drug_related', 'non_drug_related']);
            $table->string('cell_block', 50)->nullable();
            $table->date('admission_date');
            $table->enum('custody_status', ['active', 'released', 'transferred', 'deceased'])->default('active');
            $table->string('photo_path', 255)->nullable();
            $table->foreignId('registered_by')->constrained('staff_profiles', 'staff_id');
            $table->timestamps();

            $table->index('pdl_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdl_profiles');
    }
};
