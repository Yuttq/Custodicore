<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The relationship the walk-in claimed at the desk (a RelationshipRequirements
 * type), so the Record Officer can link the matched PDL in one click with the
 * right relationship type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('walk_in_assessments', function (Blueprint $table) {
            $table->string('relationship_type', 30)->nullable()->after('visit_purpose');
            $table->boolean('has_children_together')->nullable()->after('relationship_type');
        });
    }

    public function down(): void
    {
        Schema::table('walk_in_assessments', function (Blueprint $table) {
            $table->dropColumn(['relationship_type', 'has_children_together']);
        });
    }
};
