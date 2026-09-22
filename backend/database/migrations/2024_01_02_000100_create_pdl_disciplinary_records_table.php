<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// section 4.3
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdl_disciplinary_records', function (Blueprint $table) {
            $table->id('disciplinary_record_id');
            $table->foreignId('pdl_id')->constrained('pdl_profiles', 'pdl_id')->cascadeOnDelete();
            $table->date('incident_date');
            $table->text('description');
            $table->string('action_taken', 255)->nullable();
            $table->boolean('triggers_restriction')->default(false);
            $table->foreignId('recorded_by')->constrained('staff_profiles', 'staff_id');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdl_disciplinary_records');
    }
};
