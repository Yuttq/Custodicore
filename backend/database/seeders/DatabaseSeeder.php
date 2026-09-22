<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Left empty on purpose — this dashboard is not wired to the
        // database yet. Once `custodicore_schema.sql` / the matching
        // migrations run, seed roles/modules/admin accounts here.
    }
}
