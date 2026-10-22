<?php

namespace App\Support;

/**
 * One search box for dates AND text.
 *
 * Whatever the admin types is read as a date, month, year or time of day when
 * it looks like one, and otherwise falls through as ordinary text search:
 *
 *   2026-10-20 · 20/10/2026 · 20 Oct 2026 · October 20, 2026   a single day
 *   Oct 20 · 20 October                                         that day, any year
 *   October 2026 · Oct 2026 · 2026-10 · 10/2026                 a whole month
 *   October                                                     that month, any year
 *   2026                                                        a whole year
 *   today · yesterday
 *   21:58 · 21:00-22:30                                         time of day (24-hour),
 *                                                               on its own or after a date
 *
 * A slash date with two numbers up to 12 (e.g. 05/06/2026) is read day-first,
 * the way the date boxes in this system display it.
 */
class DateSearch
{
    private const MONTHS = [
        'january' => 1, 'jan' => 1, 'february' => 2, 'feb' => 2, 'march' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4, 'may' => 5, 'june' => 6, 'jun' => 6, 'july' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8, 'september' => 9, 'sept' => 9, 'sep' => 9,
        'october' => 10, 'oct' => 10, 'november' => 11, 'nov' => 11, 'december' => 12, 'dec' => 12,
    ];

    /**
     * @return array{
     *   text: ?string, from: ?string, to: ?string, month: ?int, day: ?int,
     *   time_from: ?string, time_to: ?string, label: ?string, active: bool
     * }
     *  - text:   what to search as words (null when the input was a date/time)
     *  - from/to: inclusive "YYYY-MM-DD" bounds (a day, month or year)
     *  - month/day: match this month (and day) in ANY year
     *  - time_from/time_to: "HH:MM" window within the day(s)
     *  - label:  human description of the date/time filter, for the page
     *  - active: whether any date/time filter applies
     */
    public static function parse(?string $input): array
    {
        $none = ['text' => null, 'from' => null, 'to' => null, 'month' => null, 'day' => null,
            'time_from' => null, 'time_to' => null, 'label' => null, 'active' => false];

        $raw = trim((string) $input);
        if ($raw === '') {
            return $none;
        }

        $text = ['text' => $raw] + $none;
        $s = mb_strtolower($raw);

        // 1. Time of day (24-hour), optionally a range.
        $timeFrom = $timeTo = null;
        $time = '/\b([01]?\d|2[0-3]):([0-5]\d)\b/';
        if (preg_match('/\b([01]?\d|2[0-3]):([0-5]\d)\s*(?:-|–|—|to)\s*([01]?\d|2[0-3]):([0-5]\d)\b/', $s, $m)) {
            $timeFrom = sprintf('%02d:%s', $m[1], $m[2]);
            $timeTo = sprintf('%02d:%s', $m[3], $m[4]);
            $s = str_replace($m[0], ' ', $s);
        } elseif (preg_match($time, $s, $m)) {
            $timeFrom = $timeTo = sprintf('%02d:%s', $m[1], $m[2]);
            $s = str_replace($m[0], ' ', $s);
        }

        // 2. What is left should be a date phrase (or nothing, for time-only).
        $d = trim(preg_replace('/[\s,]+/', ' ', $s) ?? '');
        $d = trim(preg_replace('/\b(on|of|the|at)\b/', ' ', $d) ?? '');
        $d = trim(preg_replace('/\s+/', ' ', $d) ?? '');
        $d = preg_replace('/\b(\d{1,2})(st|nd|rd|th)\b/', '$1', $d) ?? $d;

        $result = $none;
        $names = implode('|', array_map('preg_quote', array_keys(self::MONTHS)));

        if ($d === '') {
            if ($timeFrom === null) {
                return $text;
            }
            $result['label'] = self::timeLabel($timeFrom, $timeTo);
        } elseif ($day = self::fullDate($d, $names)) {
            $result['from'] = $result['to'] = $day;
            $result['label'] = self::dayLabel($day);
        } elseif (in_array($d, ['today', 'yesterday'], true)) {
            $day = $d === 'today' ? now()->toDateString() : now()->subDay()->toDateString();
            $result['from'] = $result['to'] = $day;
            $result['label'] = ucfirst($d) . ' (' . self::dayLabel($day) . ')';
        } elseif (preg_match('/^(\d{4})[-\/.](\d{1,2})$/', $d, $m) && self::validMonth((int) $m[2])) {
            self::setMonth($result, (int) $m[1], (int) $m[2]);
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{4})$/', $d, $m) && self::validMonth((int) $m[1])) {
            self::setMonth($result, (int) $m[2], (int) $m[1]);
        } elseif (preg_match("/^($names) (\\d{4})$/", $d, $m) || preg_match("/^(\\d{4}) ($names)$/", $d, $mm) && ($m = [1 => $mm[2], 2 => $mm[1]])) {
            self::setMonth($result, (int) $m[2], self::MONTHS[$m[1]]);
        } elseif (preg_match('/^(19|20)\d{2}$/', $d)) {
            $result['from'] = $d . '-01-01';
            $result['to'] = $d . '-12-31';
            $result['label'] = 'Year ' . $d;
        } elseif (preg_match("/^($names)$/", $d, $m)) {
            $result['month'] = self::MONTHS[$m[1]];
            $result['label'] = date('F', mktime(0, 0, 0, $result['month'], 1)) . ' (any year)';
        } elseif (preg_match("/^($names) (\\d{1,2})$/", $d, $m) || preg_match("/^(\\d{1,2}) ($names)$/", $d, $mm) && ($m = [1 => $mm[2], 2 => $mm[1]])) {
            $month = self::MONTHS[$m[1]];
            $dayNo = (int) $m[2];
            if (! checkdate($month, $dayNo, 2024)) {
                return $text;
            }
            $result['month'] = $month;
            $result['day'] = $dayNo;
            $result['label'] = date('F', mktime(0, 0, 0, $month, 1)) . ' ' . $dayNo . ' (any year)';
        } else {
            return $text; // not a date — search it as words
        }

        if ($timeFrom !== null) {
            $result['time_from'] = $timeFrom;
            $result['time_to'] = $timeTo;
            if ($d !== '') {
                $result['label'] .= ', ' . self::timeLabel($timeFrom, $timeTo);
            }
        }
        $result['active'] = true;

        return $result;
    }

    /** Adds the date/time part of a parsed search to an Eloquent / query builder. */
    public static function apply($query, string $column, array $p): void
    {
        if (! $p['active']) {
            return;
        }
        if ($p['from']) {
            $query->whereDate($column, '>=', $p['from']);
        }
        if ($p['to']) {
            $query->whereDate($column, '<=', $p['to']);
        }
        if ($p['month']) {
            $query->whereMonth($column, $p['month']);
        }
        if ($p['day']) {
            $query->whereDay($column, $p['day']);
        }
        if ($p['time_from']) {
            $start = $p['time_from'] . ':00';
            $end = $p['time_to'] . ':59';
            if ($start <= $end) {
                $query->whereTime($column, '>=', $start)->whereTime($column, '<=', $end);
            } else { // overnight window, e.g. 22:00-06:00
                $query->where(fn ($q) => $q->whereTime($column, '>=', $start)->orWhereTime($column, '<=', $end));
            }
        }
    }

    // -- helpers ------------------------------------------------------------

    /** A complete calendar date in any supported spelling → "YYYY-MM-DD", else null. */
    private static function fullDate(string $d, string $names): ?string
    {
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $d, $m)) {
            return self::ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $d, $m)) {
            [$a, $b] = [(int) $m[1], (int) $m[2]];
            // Day-first unless only month-first makes a real date (e.g. 10/20/2026).
            return $b > 12 && $a <= 12
                ? self::ymd((int) $m[3], $a, $b)
                : self::ymd((int) $m[3], $b, $a);
        }
        if (preg_match("/^($names) (\\d{1,2}) (\\d{4})$/", $d, $m)) {
            return self::ymd((int) $m[3], self::MONTHS[$m[1]], (int) $m[2]);
        }
        if (preg_match("/^(\\d{1,2}) ($names) (\\d{4})$/", $d, $m)) {
            return self::ymd((int) $m[3], self::MONTHS[$m[2]], (int) $m[1]);
        }

        return null;
    }

    private static function ymd(int $y, int $m, int $d): ?string
    {
        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }

    private static function validMonth(int $m): bool
    {
        return $m >= 1 && $m <= 12;
    }

    private static function setMonth(array &$result, int $year, int $month): void
    {
        $first = sprintf('%04d-%02d-01', $year, $month);
        $result['from'] = $first;
        $result['to'] = date('Y-m-t', strtotime($first));
        $result['label'] = date('F Y', strtotime($first));
    }

    private static function dayLabel(string $ymd): string
    {
        return date('M j, Y', strtotime($ymd));
    }

    private static function timeLabel(string $from, string $to): string
    {
        return $from === $to ? "at {$from}" : "{$from}–{$to}";
    }
}
