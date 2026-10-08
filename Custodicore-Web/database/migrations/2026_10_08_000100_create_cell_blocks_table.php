<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Master list of cell blocks / dorms, managed by the Record Officer.
// pdl_profiles.cell_block keeps storing the block *name* (no FK) so existing
// records, filters and custody history keep working; occupancy is the count
// of active PDLs whose cell_block matches the name.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cell_blocks', function (Blueprint $table) {
            $table->id('cell_block_id');
            $table->string('name', 50)->unique();
            $table->unsignedSmallInteger('capacity');
            $table->enum('designation', ['any', 'male', 'female'])->default('any');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Carry over every block name already typed onto a PDL so nothing
        // becomes "unknown". Capacity defaults to 20, or the current active
        // headcount if that's higher — the officer can adjust it afterwards.
        $existing = DB::table('pdl_profiles')
            ->whereNotNull('cell_block')
            ->where('cell_block', '!=', '')
            ->select('cell_block')
            ->selectRaw("SUM(CASE WHEN custody_status = 'active' THEN 1 ELSE 0 END) as occupied")
            ->groupBy('cell_block')
            ->get();

        foreach ($existing as $row) {
            DB::table('cell_blocks')->insert([
                'name' => $row->cell_block,
                'capacity' => max(20, (int) $row->occupied),
                'designation' => 'any',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cell_blocks');
    }
};
