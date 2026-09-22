<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// section 7.3 — saved copies of printable/downloadable reports
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_reports', function (Blueprint $table) {
            $table->id('report_id');
            $table->string('report_type', 50);
            $table->foreignId('generated_by')->constrained('staff_profiles', 'staff_id');
            $table->date('date_range_start');
            $table->date('date_range_end');
            $table->string('file_path', 255);
            $table->timestamp('generated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_reports');
    }
};
