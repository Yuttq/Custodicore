<?php

namespace Tests\Unit;

use Illuminate\Database\Connectors\MySqlConnector;
use Mockery;
use PDO;
use Tests\TestCase;

/**
 * The MySQL connection runs in Philippine time (+08:00), so columns filled by
 * the database clock (useCurrent()) match the Asia/Manila times the app writes.
 *
 * No MySQL server is involved: the tests read the config and check the SET
 * statement Laravel would send on connect, using a fake PDO. Whether a real
 * server accepts it is checked against MySQL 8 separately.
 */
class MysqlSessionTimezoneTest extends TestCase
{
    public function test_mysql_connection_uses_philippine_time(): void
    {
        $this->assertSame('+08:00', config('database.connections.mysql.timezone'));
    }

    public function test_app_time_zone_is_unchanged(): void
    {
        $this->assertSame('Asia/Manila', config('app.timezone'));
    }

    public function test_connecting_sets_the_session_time_zone(): void
    {
        $config = config('database.connections.mysql');

        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('getAttribute')->with(PDO::ATTR_SERVER_VERSION)->andReturn('8.4.0');
        $pdo->shouldReceive('exec')
            ->once()
            ->with(Mockery::on(fn (string $sql) => str_starts_with($sql, 'SET ')
                && str_contains($sql, "time_zone='+08:00'")))
            ->andReturn(0);

        $configure = new \ReflectionMethod(MySqlConnector::class, 'configureConnection');
        $configure->invoke(new MySqlConnector, $pdo, $config);
    }
}
