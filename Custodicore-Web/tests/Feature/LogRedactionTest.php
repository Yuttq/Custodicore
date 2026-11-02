<?php

namespace Tests\Feature;

use App\Logging\RedactQueryBindings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * Failed queries must not put visitor/staff values into the log files, and
 * the log must still say what failed. All values here are synthetic; logs go
 * to a temporary folder, never storage/logs.
 */
class LogRedactionTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cc-log-redaction-' . uniqid();
        mkdir($this->logDir);

        config(['logging.channels.redaction_test' => [
            'driver' => 'single',
            'path' => $this->logDir . DIRECTORY_SEPARATOR . 'test.log',
            'level' => 'debug',
            'tap' => [RedactQueryBindings::class],
        ]]);
        Log::forgetChannel('redaction_test');
    }

    protected function tearDown(): void
    {
        Log::forgetChannel('redaction_test');
        Log::forgetChannel('stack');
        Log::forgetChannel('daily');

        foreach (glob($this->logDir . DIRECTORY_SEPARATOR . '*') as $file) {
            unlink($file);
        }
        rmdir($this->logDir);

        parent::tearDown();
    }

    private function logged(string $file = 'test.log'): string
    {
        return (string) file_get_contents($this->logDir . DIRECTORY_SEPARATOR . $file);
    }

    private function failingQuery(callable $query): QueryException
    {
        try {
            $query();
        } catch (QueryException $e) {
            return $e;
        }

        $this->fail('Expected the query to fail.');
    }

    public function test_explicitly_logged_query_error_does_not_contain_the_visitor_email(): void
    {
        $e = $this->failingQuery(fn () => DB::table('missing_accounts')->where('email', 'synthetic.visitor@example.test')->first());
        $this->assertStringContainsString('synthetic.visitor@example.test', $e->getMessage(), 'precondition: Laravel puts the value in the message');

        // Same shape as the controllers' catch blocks: only the string is logged.
        Log::channel('redaction_test')->error('Visitor approval failed: ' . $e->getMessage());

        $log = $this->logged();
        $this->assertStringNotContainsString('synthetic.visitor@example.test', $log);
        $this->assertStringContainsString('Visitor approval failed: ', $log);
        $this->assertStringContainsString('no such table: missing_accounts', $log);
        $this->assertStringContainsString('Connection: sqlite', $log);
        $this->assertStringContainsString('SQL: select * from "missing_accounts" where "email" = ? limit 1)', $log);
    }

    public function test_personal_values_and_secrets_in_a_failed_insert_are_not_logged(): void
    {
        $values = [
            'first_name' => 'Synthia',
            'last_name' => 'Testperson',
            'contact_number' => '09170000001',
            'address' => '123 Sample Street, Testville',
            'id_number' => 'SYN-ID-0001',
            'birthdate' => '1990-01-31',
            'password_hash' => 'synthetic-password-hash',
            'invitation_token_hash' => 'synthetic-invitation-token',
        ];

        $e = $this->failingQuery(fn () => DB::table('missing_visitor_profiles')->insert($values));
        Log::channel('redaction_test')->error('Walk-in registration failed: ' . $e->getMessage());

        $log = $this->logged();
        foreach ($values as $value) {
            $this->assertStringNotContainsString($value, $log);
        }
        $this->assertStringContainsString(
            'insert into "missing_visitor_profiles" ("first_name", "last_name", "contact_number", "address", "id_number", "birthdate", "password_hash", "invitation_token_hash") values (?, ?, ?, ?, ?, ?, ?, ?)',
            $log
        );
    }

    public function test_unhandled_query_exception_is_redacted_in_message_and_context(): void
    {
        config(['logging.default' => 'redaction_test']);

        $e = $this->failingQuery(fn () => DB::table('missing_accounts')->where('email', 'synthetic.visitor@example.test')->first());
        report($e);

        $log = $this->logged();
        $this->assertStringNotContainsString('synthetic.visitor@example.test', $log);
        // Stack trace arguments are shortened, not removed, by PHP: check the prefix too.
        $this->assertStringNotContainsString('synthetic.visi', $log);
        // Context is written as JSON, so class names appear with doubled backslashes.
        $this->assertStringContainsString('QueryException(code: HY000): SQLSTATE[HY000]: General error: 1 no such table: missing_accounts', $log);
        $this->assertStringContainsString('[previous exception] [object] (PDOException(code: HY000)', $log);
        $this->assertStringContainsString('where \"email\" = ? limit 1', $log);
        $this->assertStringContainsString('[stacktrace]', $log);
        // Frames keep file, line and function, but not the call arguments.
        $this->assertStringContainsString('Connection->runQueryCallback()', $log);
    }

    public function test_driver_message_values_and_unknown_queries_are_redacted(): void
    {
        // A MySQL duplicate-key error for a query this process never ran, logged as text only.
        RedactQueryBindings::forgetRecentQueries();
        $pdo = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'dup.visitor@example.test' for key 'accounts_email_unique'");
        $e = new QueryException('mysql', 'insert into `accounts` (`username`, `email`) values (?, ?)', ['dupvisitor', 'dup.visitor@example.test'], $pdo, ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'custodicore']);

        Log::channel('redaction_test')->error('Account creation failed: ' . $e->getMessage());

        $log = $this->logged();
        $this->assertStringNotContainsString('dup.visitor@example.test', $log);
        $this->assertStringNotContainsString('dupvisitor', $log);
        $this->assertStringContainsString("Duplicate entry '[redacted]' for key 'accounts_email_unique'", $log);
        $this->assertStringContainsString('Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: custodicore', $log);
        $this->assertStringContainsString('SQL: insert into `accounts` (`username`, `email`) values [redacted])', $log);
    }

    public function test_driver_message_value_is_redacted_when_the_exception_is_in_context(): void
    {
        $pdo = new PDOException("SQLSTATE[22007]: Invalid datetime format: 1292 Incorrect date value: '1990-02-31' for column `custodicore`.`visitor_profiles`.`birthdate` at row 1");
        $e = new QueryException('mysql', 'update `visitor_profiles` set `birthdate` = ? where `visitor_id` = ?', ['1990-02-31', 7], $pdo);

        Log::channel('redaction_test')->error($e->getMessage(), ['exception' => $e]);

        $log = $this->logged();
        $this->assertStringNotContainsString('1990-02-31', $log);
        $this->assertStringContainsString("Incorrect date value: '[redacted]' for column", $log);
        $this->assertStringContainsString('update `visitor_profiles` set `birthdate` = ? where `visitor_id` = ?', $log);
    }

    public function test_other_errors_are_logged_unchanged(): void
    {
        config(['logging.default' => 'redaction_test']);

        Log::channel('redaction_test')->warning('Approval email could not be sent for visitor #7 (RuntimeException)');
        report(new RuntimeException('PDL record #42 could not be rendered'));

        $log = $this->logged();
        $this->assertStringContainsString('Approval email could not be sent for visitor #7 (RuntimeException)', $log);
        $this->assertStringContainsString('PDL record #42 could not be rendered {"exception":"[object] (RuntimeException(code: 0): PDL record #42 could not be rendered at ', $log);
        // Monolog's normal trace is kept for non-database errors.
        $this->assertStringContainsString(__FUNCTION__, $log);
    }

    public function test_daily_channel_rotates_with_14_day_retention_and_redacts(): void
    {
        $daily = config('logging.channels.daily');
        $this->assertSame('daily', $daily['driver']);
        $this->assertSame(storage_path('logs/laravel.log'), $daily['path']);
        $this->assertContains(RedactQueryBindings::class, $daily['tap']);
        $this->assertContains(RedactQueryBindings::class, config('logging.channels.single.tap'));

        // LOG_STACK=daily (as deploy/server-setup.sh sets it) resolves to a real channel.
        config([
            'logging.channels.stack.channels' => ['daily'],
            'logging.channels.daily.path' => $this->logDir . DIRECTORY_SEPARATOR . 'laravel.log',
        ]);

        $e = $this->failingQuery(fn () => DB::table('missing_accounts')->where('email', 'synthetic.visitor@example.test')->first());
        Log::channel('stack')->error('Login lookup failed: ' . $e->getMessage());

        $log = $this->logged('laravel-' . date('Y-m-d') . '.log');
        $this->assertStringContainsString('Login lookup failed: ', $log);
        $this->assertStringNotContainsString('synthetic.visitor@example.test', $log);
        $this->assertStringNotContainsString('emergency logger', $log);
    }

    public function test_fallback_level_is_warning_and_retention_defaults_to_14_days(): void
    {
        $config = $this->loggingConfigWithout(['LOG_LEVEL', 'LOG_DAILY_DAYS']);

        $this->assertSame('warning', $config['channels']['single']['level']);
        $this->assertSame('warning', $config['channels']['daily']['level']);
        $this->assertSame(14, $config['channels']['daily']['days']);
    }

    public function test_explicit_env_values_still_win(): void
    {
        $config = $this->loggingConfigWithout([], ['LOG_LEVEL' => 'debug', 'LOG_DAILY_DAYS' => '30']);

        $this->assertSame('debug', $config['channels']['single']['level']);
        $this->assertSame(30, $config['channels']['daily']['days']);
    }

    /**
     * Load config/logging.php with some env variables removed or set, then
     * put them back.
     */
    private function loggingConfigWithout(array $unset, array $set = []): array
    {
        $names = array_merge($unset, array_keys($set));
        $saved = [];
        foreach ($names as $name) {
            $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
        foreach ($set as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }

        try {
            return require config_path('logging.php');
        } finally {
            foreach ($saved as $name => [$env, $server, $putenv]) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);
                if ($env !== null) {
                    $_ENV[$name] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }
                if ($putenv !== false) {
                    putenv("{$name}={$putenv}");
                }
            }
        }
    }
}
