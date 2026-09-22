<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// section 5.4 — qr_token is a separate random value, never the row's PK
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id('qr_code_id');
            $table->foreignId('visit_request_id')->unique()->constrained('visit_requests', 'visit_request_id')->cascadeOnDelete();
            $table->char('qr_token', 64)->unique();
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->enum('status', ['active', 'used', 'expired', 'void'])->default('active');

            $table->index('qr_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
    }
};
