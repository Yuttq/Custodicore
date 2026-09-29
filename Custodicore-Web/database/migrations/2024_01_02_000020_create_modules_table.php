<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// section 3.2
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id('module_id');
            $table->string('module_code', 50)->unique();
            $table->string('module_name', 100);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
