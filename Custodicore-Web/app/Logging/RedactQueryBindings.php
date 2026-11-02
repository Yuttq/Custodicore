<?php

namespace App\Logging;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Log\Logger;
use Illuminate\Support\Str;
use Monolog\LogRecord;
use Throwable;
use WeakMap;

/**
 * Log tap that keeps query values (visitor names, emails, phone numbers,
 * ID numbers, ...) out of the log files.
 *
 * Laravel's QueryException message is "<driver message> (Connection: ...,
 * SQL: <sql with every ? replaced by its value>)", and the app logs that
 * message both directly (Log::error('... failed: ' . $e->getMessage())) and
 * through the exception handler. This tap rewrites each record before it is
 * written:
 *
 *  - SQL with values -> the same SQL with ? placeholders. The values come from
 *    the QueryException itself (getSql()/getBindings()) when it is in the log
 *    context, otherwise from the last few queries this process ran (recorded
 *    by watch()), which is how plain-string log calls are covered.
 *  - If neither source matches, the SQL is cut before its first value clause.
 *  - Values the database quotes in its own message (Duplicate entry '...',
 *    Incorrect ... value: '...') are replaced with [redacted].
 *  - A database exception in the context is written as class, code, cleaned
 *    message, file:line and a stack trace without call arguments.
 *
 * Connection name/host/database, SQLSTATE codes, column and key names stay in
 * the log. Records with no database error in them are left untouched.
 */
class RedactQueryBindings
{
    public const REDACTED = '[redacted]';

    /** How many recent queries to remember for plain-string log calls. */
    private const RECENT_QUERIES = 25;

    /** @var array<int, array{0: string, 1: array, 2: Connection}> */
    private static array $recentQueries = [];

    private static ?WeakMap $watched = null;

    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(fn (LogRecord $record) => self::redactRecord($record));
    }

    /**
     * Remember the SQL and bindings of each query run on this connection, so a
     * failed query can be matched in a message that was logged as plain text.
     */
    public static function watch(Connection $connection): void
    {
        self::$watched ??= new WeakMap;

        if (isset(self::$watched[$connection])) {
            return;
        }
        self::$watched[$connection] = true;

        $connection->beforeExecuting(function ($query, $bindings, $connection) {
            self::$recentQueries[] = [(string) $query, (array) $bindings, $connection];

            if (count(self::$recentQueries) > self::RECENT_QUERIES) {
                array_shift(self::$recentQueries);
            }
        });
    }

    public static function forgetRecentQueries(): void
    {
        self::$recentQueries = [];
    }

    public static function redactRecord(LogRecord $record): LogRecord
    {
        try {
            $queries = self::queriesFromContext($record->context);

            if (! $queries && ! self::looksLikeQueryError($record->message)) {
                return $record;
            }

            if (str_contains($record->message, 'SQL: ')) {
                $queries = array_merge($queries, self::recentQueries());
            }

            return $record->with(
                message: self::redactText($record->message, $queries),
                context: self::redactContext($record->context, $queries),
            );
        } catch (Throwable $e) {
            // Never let a redaction bug write the raw values instead.
            return $record->with(
                message: '[log message withheld: redaction failed (' . $e::class . ')]',
                context: [],
            );
        }
    }

    /**
     * Replace query values in a piece of log text.
     *
     * @param  array<int, array{0: string, 1: array}>  $queries  [sql, bindings] pairs
     */
    public static function redactText(string $text, array $queries): string
    {
        if (! self::looksLikeQueryError($text)) {
            return $text;
        }

        $placeholderSql = [];

        foreach ($queries as [$sql, $bindings]) {
            $placeholderSql[] = $sql;

            if (! $bindings) {
                continue;
            }

            // Built exactly like QueryException::formatMessage().
            $withValues = Str::replaceArray('?', $bindings, $sql);

            if ($withValues !== $sql && str_contains($text, $withValues)) {
                $text = str_replace($withValues, $sql, $text);
                $text = self::redactQuotedValues($text, $bindings);
            }
        }

        // SQL that matched no known query still has its values in it.
        $text = preg_replace_callback('/, SQL: (.*)\)/s', function (array $m) use ($placeholderSql) {
            return in_array($m[1], $placeholderSql, true)
                ? $m[0]
                : ', SQL: ' . self::truncateBeforeValues($m[1]) . ')';
        }, $text) ?? $text;

        return self::redactDriverValues($text);
    }

    private static function looksLikeQueryError(string $text): bool
    {
        return str_contains($text, 'SQL');
    }

    /**
     * @return array<int, array{0: string, 1: array}>
     */
    private static function queriesFromContext(array $context, int $depth = 0): array
    {
        $queries = [];

        foreach ($context as $value) {
            if ($value instanceof Throwable) {
                foreach (self::chain($value) as $e) {
                    if ($e instanceof QueryException) {
                        $queries[] = [(string) $e->getSql(), $e->getBindings()];
                    }
                }
            } elseif (is_array($value) && $depth < 3) {
                $queries = array_merge($queries, self::queriesFromContext($value, $depth + 1));
            }
        }

        return $queries;
    }

    /**
     * @return array<int, array{0: string, 1: array}>
     */
    private static function recentQueries(): array
    {
        $queries = [];

        foreach (array_reverse(self::$recentQueries) as [$sql, $bindings, $connection]) {
            // QueryException holds prepared bindings (dates formatted, bools as ints).
            $queries[] = [$sql, $connection->prepareBindings($bindings)];
        }

        return $queries;
    }

    private static function redactContext(array $context, array $queries, int $depth = 0): array
    {
        foreach ($context as $key => $value) {
            if ($value instanceof Throwable && self::involvesQuery($value)) {
                $context[$key] = self::describe($value, $queries);
            } elseif (is_string($value)) {
                $context[$key] = self::redactText($value, $queries);
            } elseif (is_array($value) && $depth < 3) {
                $context[$key] = self::redactContext($value, $queries, $depth + 1);
            }
        }

        return $context;
    }

    private static function involvesQuery(Throwable $e): bool
    {
        foreach (self::chain($e) as $link) {
            if ($link instanceof QueryException) {
                return true;
            }
        }

        return false;
    }

    /**
     * Same layout as Monolog's LineFormatter, but with cleaned messages and
     * no call arguments in the stack trace (arguments can hold values too).
     */
    private static function describe(Throwable $e, array $queries): string
    {
        $parts = [];

        foreach (self::chain($e) as $link) {
            $parts[] = '[object] (' . $link::class . '(code: ' . $link->getCode() . '): '
                . self::redactText($link->getMessage(), $queries)
                . ' at ' . $link->getFile() . ':' . $link->getLine() . ')'
                . "\n[stacktrace]\n" . self::traceWithoutArguments($link);
        }

        return implode("\n[previous exception] ", $parts);
    }

    private static function traceWithoutArguments(Throwable $e): string
    {
        $lines = [];

        foreach ($e->getTrace() as $i => $frame) {
            $where = isset($frame['file']) ? $frame['file'] . '(' . ($frame['line'] ?? '?') . ')' : '[internal function]';
            $call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
            $lines[] = '#' . $i . ' ' . $where . ': ' . $call . '()';
        }

        $lines[] = '#' . count($lines) . ' {main}';

        return implode("\n", $lines);
    }

    /**
     * @return array<int, Throwable>
     */
    private static function chain(Throwable $e): array
    {
        $chain = [];

        while ($e && count($chain) < 10) {
            $chain[] = $e;
            $e = $e->getPrevious();
        }

        return $chain;
    }

    /**
     * Quoted segments in the driver's message that contain one of the
     * query's values, e.g. Duplicate entry 'juan@example.com' for key ...
     */
    private static function redactQuotedValues(string $text, array $bindings): string
    {
        $values = [];

        foreach ($bindings as $binding) {
            if (is_scalar($binding) && strlen((string) $binding) >= 3) {
                $values[] = (string) $binding;
            }
        }

        if (! $values) {
            return $text;
        }

        return preg_replace_callback("/'([^']*)'/", function (array $m) use ($values) {
            foreach ($values as $value) {
                // MySQL may shorten the value it echoes back.
                if (str_contains($m[1], $value) || (strlen($m[1]) >= 3 && str_starts_with($value, $m[1]))) {
                    return "'" . self::REDACTED . "'";
                }
            }

            return $m[0];
        }, $text) ?? $text;
    }

    /**
     * MySQL/MariaDB messages that echo a value even when the query that
     * failed is unknown.
     */
    private static function redactDriverValues(string $text): string
    {
        $text = preg_replace("/(Duplicate entry )'.*?'(?= for key)/s", "$1'" . self::REDACTED . "'", $text) ?? $text;

        return preg_replace("/((?:Incorrect|Truncated incorrect) [\\w ]*value: )'.*?'(?=\\s+for\\b|\\s*\\(|\\s*$)/s", "$1'" . self::REDACTED . "'", $text) ?? $text;
    }

    /**
     * Keep "insert into `t` (`a`, `b`)" / "select * from `t`" and drop
     * everything from the first clause that can carry values.
     */
    private static function truncateBeforeValues(string $sql): string
    {
        if (preg_match('/\s(values|set|where|having|on|limit|offset)\b/i', $sql, $m, PREG_OFFSET_CAPTURE)) {
            return substr($sql, 0, $m[0][1]) . ' ' . $m[1][0] . ' ' . self::REDACTED;
        }

        return self::REDACTED;
    }
}
