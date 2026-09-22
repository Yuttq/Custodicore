<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// section 3.5
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id('staff_id');
            $table->foreignId('account_id')->unique()->constrained('accounts', 'account_id')->cascadeOnDelete();
            $table->string('employee_number', 30)->unique();
            $table->string('full_name', 150);
            $table->string('position', 100)->nullable();
            $table->string('contact_number', 20)->nullable();
            $table->string('assigned_facility', 150)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_profiles');
    }
};
