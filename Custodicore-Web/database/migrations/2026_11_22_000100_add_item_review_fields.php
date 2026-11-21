<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reject-only review of each item a visitor submits.
 *
 * visitor_profiles — the visitor's own photo gets its own review, like a
 * government ID:
 *   photo_status            pending | verified | rejected (null: no photo)
 *   photo_rejection_reason  visitor-facing reason when staff reject it (or ask
 *                           for a new one); kept after a retake so staff see
 *                           what was asked for, cleared when it is verified
 *   photo_reviewed_by/_at   who decided it and when
 *
 * visitor_ids.verification_status — adds `superseded`: a duplicate ID set
 * aside by staff (VisitorController::setAsideId). The row, its file and its
 * history are kept; it is never shown to the visitor or approved.
 *
 * Existing rows: a photo on an approved account is verified by the same
 * officer at the same time; any other photo is waiting for review. Nothing
 * is deleted.
 */
return new class extends Migration
{
    /**
     * Outside a transaction, so SQLite (tests, local) honours the foreign-key
     * switch below while it rebuilds a table; MySQL alters in place anyway.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('visitor_profiles', function (Blueprint $table) {
            $table->string('photo_status', 20)->nullable()->after('photo_uploaded_at');
            $table->string('photo_rejection_reason', 500)->nullable()->after('photo_status');
            $table->foreignId('photo_reviewed_by')->nullable()->after('photo_rejection_reason')
                ->constrained('staff_profiles', 'staff_id')->nullOnDelete();
            $table->timestamp('photo_reviewed_at')->nullable()->after('photo_reviewed_by');
        });

        // SQLite rebuilds the table for change(): keep child rows (no cascades).
        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('visitor_ids', function (Blueprint $table) {
                $table->enum('verification_status', ['pending', 'verified', 'rejected', 'superseded'])
                    ->default('pending')->change();
            });
        });

        DB::table('visitor_profiles')
            ->whereNotNull('photo_path')
            ->where('verification_status', 'verified')
            ->update([
                'photo_status' => 'verified',
                'photo_reviewed_by' => DB::raw('verified_by'),
                'photo_reviewed_at' => DB::raw('verified_at'),
            ]);

        DB::table('visitor_profiles')
            ->whereNotNull('photo_path')
            ->whereNull('photo_status')
            ->update(['photo_status' => 'pending']);
    }

    public function down(): void
    {
        // A set-aside duplicate becomes a rejected ID again (still kept).
        DB::table('visitor_ids')->where('verification_status', 'superseded')->update(['verification_status' => 'rejected']);

        // SQLite rebuilds both tables here: without the switch, rebuilding
        // visitor_profiles would cascade-delete its child rows.
        Schema::withoutForeignKeyConstraints(function () {
            Schema::table('visitor_ids', function (Blueprint $table) {
                $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending')->change();
            });

            Schema::table('visitor_profiles', function (Blueprint $table) {
                $table->dropConstrainedForeignId('photo_reviewed_by');
                $table->dropColumn(['photo_status', 'photo_rejection_reason', 'photo_reviewed_at']);
            });
        });
    }
};
