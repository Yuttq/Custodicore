<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// section 3.3 — the role -> module "Can Access" matrix from the approved brief
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id('role_permission_id');
            $table->foreignId('role_id')->constrained('roles', 'role_id')->cascadeOnDelete();
            $table->foreignId('module_id')->constrained('modules', 'module_id')->cascadeOnDelete();
            $table->boolean('can_view')->default(false);
            $table->boolean('can_create')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['role_id', 'module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
