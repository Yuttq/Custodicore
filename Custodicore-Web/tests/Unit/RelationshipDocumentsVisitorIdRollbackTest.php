<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Rolling back 2026_10_15_000900 on MySQL: the visitor_id foreign key is
 * backed by the (visitor_id, requirement_key) index, so MySQL refuses to
 * drop that index while the key exists (error 1553). SQLite rebuilds the
 * table and never hit this, so the check reads the MySQL statements the
 * rollback would run.
 *
 * No MySQL server is involved: the connection only pretends, so nothing is
 * sent. Running the rollback on a real server is checked against MySQL 8
 * separately.
 */
class RelationshipDocumentsVisitorIdRollbackTest extends TestCase
{
    public function test_rollback_drops_the_foreign_key_before_its_index_and_column(): void
    {
        // Unreachable on purpose: pretending must never touch a real database.
        config(['database.connections.mysql_pretend' => [
            ...config('database.connections.mysql'),
            'host' => '127.0.0.1', 'port' => 1, 'database' => 'pretend_only',
        ]]);
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('mysql_pretend');

        try {
            $migration = require database_path('migrations/2026_10_15_000900_add_visitor_id_to_relationship_documents_table.php');
            $statements = array_column(DB::pretend(fn () => $migration->down()), 'query');
        } finally {
            DB::setDefaultConnection($default);
        }

        $position = function (string $needle) use ($statements): int {
            foreach ($statements as $i => $sql) {
                if (str_contains($sql, $needle)) {
                    return $i;
                }
            }
            $this->fail("No statement contains [$needle]:\n".implode("\n", $statements));
        };

        $foreignKey = $position('drop foreign key `relationship_documents_visitor_id_foreign`');
        $index = $position('drop index `relationship_documents_visitor_id_requirement_key_index`');
        $column = $position('drop `visitor_id`');

        $this->assertLessThan($index, $foreignKey, 'Foreign key dropped before the index that backs it.');
        $this->assertLessThan($column, $index, 'Index dropped before its column.');
    }
}
