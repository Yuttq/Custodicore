<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A replacement ID recorded by the Front Desk at check-in has no uploaded
 * photo: the officer only enters its type and number, and the Records
 * Officer verifies it later. file_path must allow null for that row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_ids', function (Blueprint $table) {
            $table->string('file_path', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('visitor_ids', function (Blueprint $table) {
            $table->string('file_path', 255)->nullable(false)->change();
        });
    }
};
