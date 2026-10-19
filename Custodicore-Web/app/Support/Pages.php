<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator as PaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * One page size for every list in the system, and a way to page lists that
 * were built as plain arrays (the Admin dashboard pages map rows to arrays).
 */
class Pages
{
    /** Rows shown per page on every list. */
    public const PER_PAGE = 10;

    /**
     * Slice already-built rows into the requested ?page=, keeping the other
     * query string (search text etc.) in the Previous / Next / number links.
     */
    public static function of(array|Collection $rows, int $perPage = self::PER_PAGE): PaginatorContract
    {
        $request = request();
        $rows = collect($rows)->values();

        $last = max(1, (int) ceil($rows->count() / $perPage));
        $page = min(max(1, (int) $request->query('page', 1)), $last);

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->except('page')]
        );
    }
}
