<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// section 7.1 — Module 1.7, every account action across every module
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id('audit_log_id');
            $table->foreignId('account_id')->nullable()->constrained('accounts', 'account_id')->nullOnDelete();
            $table->enum('action_type', [
                'login', 'logout', 'create', 'update', 'delete',
                'approve', 'deny', 'check_in', 'check_out',
            ]);
            $table->foreignId('module_id')->constrained('modules', 'module_id');
            $table->string('record_type', 50)->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->string('description', 255);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
