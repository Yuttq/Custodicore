<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// section 4.2
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdl_legal_records', function (Blueprint $table) {
            $table->id('legal_record_id');
            $table->foreignId('pdl_id')->constrained('pdl_profiles', 'pdl_id')->cascadeOnDelete();
            $table->string('case_number', 50);
            $table->string('offense', 255);
            $table->string('court', 150)->nullable();
            $table->string('case_status', 100)->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('recorded_by')->constrained('staff_profiles', 'staff_id');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdl_legal_records');
    }
};
