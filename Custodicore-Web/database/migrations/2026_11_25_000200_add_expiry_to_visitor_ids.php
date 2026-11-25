<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a visitor's government ID expires, as the Record Officer read it off
 * the ID (VisitorId::expiryState):
 *   expiry_date — the "valid until" date printed on the ID
 *   no_expiry   — the ID has no expiry date (e.g. the PhilSys National ID)
 * Both empty: not recorded yet (IDs approved before this was added).
 * An ID that has expired by the visit date blocks booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_ids', function (Blueprint $table) {
            $table->date('expiry_date')->nullable();
            $table->boolean('no_expiry')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('visitor_ids', function (Blueprint $table) {
            $table->dropColumn(['expiry_date', 'no_expiry']);
        });
    }
};
