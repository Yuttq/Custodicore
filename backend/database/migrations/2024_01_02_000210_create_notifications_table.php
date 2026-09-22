<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// section 7.2 — CustodiCore's own notifications table (distinct from
// Laravel's built-in notifications system, which this app doesn't use)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id('notification_id');
            $table->foreignId('account_id')->constrained('accounts', 'account_id')->cascadeOnDelete();
            $table->string('notification_type', 50);
            $table->string('title', 150);
            $table->string('message', 500);
            $table->string('related_record_type', 50)->nullable();
            $table->unsignedBigInteger('related_record_id')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamp('read_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
