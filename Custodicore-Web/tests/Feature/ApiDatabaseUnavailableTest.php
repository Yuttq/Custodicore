<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PDOException;
use Tests\TestCase;

/**
 * bootstrap/app.php: on api/* requests, a database that cannot be reached
 * answers 503 `service_unavailable` (the mobile app shows "Service
 * temporarily unavailable"). Other query errors and web pages keep the
 * default handling.
 *
 * The "unreachable" connection is real pdo_mysql pointed at a closed local
 * port, so the error comes from the driver itself. No MySQL server is needed;
 * a stopped Azure database is not exercised here.
 */
class ApiDatabaseUnavailableTest extends TestCase
{
    private const SERVICE_UNAVAILABLE = [
        'message' => 'Service temporarily unavailable. Please try again later.',
        'code' => 'service_unavailable',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.unreachable_mysql' => array_merge(
            config('database.connections.mysql'),
            ['host' => '127.0.0.1', 'port' => 1, 'database' => 'none', 'username' => 'none', 'password' => ''],
        )]);

        Route::get('/api/test-db/unreachable', fn () => DB::connection('unreachable_mysql')->select('select 1'));
        Route::get('/api/test-db/missing-table', fn () => DB::select('select * from no_such_table'));
        Route::get('/api/test-db/gone-away', fn () => throw self::queryExceptionWith(
            new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'),
            ['HY000', 2006, 'MySQL server has gone away'],
        ));
        Route::get('/api/test-db/duplicate', fn () => throw self::queryExceptionWith(
            new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry'),
            ['23000', 1062, 'Duplicate entry'],
        ));
        Route::get('/api/test-db/access-denied', fn () => throw self::queryExceptionWith(
            new PDOException('SQLSTATE[HY000] [1045] Access denied for user'),
        ));
        Route::get('/test-db/unreachable', fn () => DB::connection('unreachable_mysql')->select('select 1'));
    }

    private static function queryExceptionWith(PDOException $pdo, ?array $errorInfo = null): QueryException
    {
        $pdo->errorInfo = $errorInfo;

        return new QueryException('mysql', 'select 1', [], $pdo);
    }

    public function test_mysql_connect_timeout_is_set(): void
    {
        $this->assertSame(5, config('database.connections.mysql.options')[\PDO::ATTR_TIMEOUT]);
    }

    public function test_unreachable_database_on_api_route_returns_503(): void
    {
        $this->getJson('/api/test-db/unreachable')
            ->assertStatus(503)
            ->assertExactJson(self::SERVICE_UNAVAILABLE);
    }

    public function test_503_applies_without_an_accept_json_header(): void
    {
        // The mobile client sends only Content-Type, not Accept: application/json.
        $this->get('/api/test-db/unreachable')
            ->assertStatus(503)
            ->assertExactJson(self::SERVICE_UNAVAILABLE);
    }

    public function test_database_failure_in_auth_middleware_returns_503(): void
    {
        // Sanctum looks the bearer token up before any controller runs.
        config(['database.default' => 'unreachable_mysql']);
        DB::purge();

        $this->getJson('/api/me', ['Authorization' => 'Bearer 1|not-a-real-token'])
            ->assertStatus(503)
            ->assertExactJson(self::SERVICE_UNAVAILABLE);
    }

    public function test_connection_lost_mid_request_returns_503(): void
    {
        $this->getJson('/api/test-db/gone-away')
            ->assertStatus(503)
            ->assertJson(['code' => 'service_unavailable']);
    }

    public function test_ordinary_query_error_stays_500(): void
    {
        $this->getJson('/api/test-db/missing-table')
            ->assertStatus(500)
            ->assertJsonMissing(['code' => 'service_unavailable']);
    }

    public function test_constraint_violation_stays_500(): void
    {
        $this->getJson('/api/test-db/duplicate')
            ->assertStatus(500)
            ->assertJsonMissing(['code' => 'service_unavailable']);
    }

    public function test_access_denied_is_not_treated_as_an_outage(): void
    {
        $this->getJson('/api/test-db/access-denied')
            ->assertStatus(500)
            ->assertJsonMissing(['code' => 'service_unavailable']);
    }

    public function test_web_route_keeps_default_handling(): void
    {
        $response = $this->get('/test-db/unreachable');

        $response->assertStatus(500);
        $this->assertStringNotContainsString('service_unavailable', (string) $response->getContent());
    }

    public function test_api_validation_errors_are_unchanged(): void
    {
        $this->postJson('/api/auth/login', [])->assertStatus(422);
    }
}
