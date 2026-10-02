<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_ids', function (Blueprint $table) {
            $table->string('reason', 255)
                ->nullable()
                ->after('verification_status');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_ids', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};