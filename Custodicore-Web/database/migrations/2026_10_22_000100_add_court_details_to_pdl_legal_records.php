<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legal records get a fixed case status, the date the case was filed, the
 * conviction (date + sentence) and court hearings (pdl_court_hearings: the
 * next one plus the past ones as history). Existing free-text statuses are
 * mapped to the new list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdl_legal_records', function (Blueprint $table) {
            $table->date('date_filed')->nullable()->after('court');
            $table->date('convicted_on')->nullable()->after('case_status');
            $table->unsignedSmallInteger('sentence_years')->nullable()->after('convicted_on');
            $table->unsignedSmallInteger('sentence_months')->nullable()->after('sentence_years');
            $table->unsignedSmallInteger('sentence_days')->nullable()->after('sentence_months');
            $table->timestamp('updated_at')->nullable()->after('created_at');
        });

        Schema::create('pdl_court_hearings', function (Blueprint $table) {
            $table->id('hearing_id');
            $table->foreignId('legal_record_id')->constrained('pdl_legal_records', 'legal_record_id')->cascadeOnDelete();
            $table->dateTime('hearing_at');
            $table->string('notes', 255)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('staff_profiles', 'staff_id')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['legal_record_id', 'hearing_at']);
        });

        // Free text → the fixed list (stored as readable labels).
        foreach (DB::table('pdl_legal_records')->get(['legal_record_id', 'case_status']) as $row) {
            $text = strtolower((string) $row->case_status);
            $status = match (true) {
                str_contains($text, 'appeal') => 'Convicted, on appeal',
                str_contains($text, 'convict') => 'Convicted',
                str_contains($text, 'acquit') => 'Acquitted',
                str_contains($text, 'dismiss') => 'Dismissed',
                default => 'Pending trial',
            };
            DB::table('pdl_legal_records')->where('legal_record_id', $row->legal_record_id)->update(['case_status' => $status]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pdl_court_hearings');

        Schema::table('pdl_legal_records', function (Blueprint $table) {
            $table->dropColumn(['date_filed', 'convicted_on', 'sentence_years', 'sentence_months', 'sentence_days', 'updated_at']);
        });
    }
};
