{{--
    Front Desk list footer: "Showing 1–25 of 80 entries" and numbered pages
    (‹ 1 … 4 5 6 … 12 ›), keeping the page's filters. Shown on empty lists
    too ("Showing 0 of 0 entries"), so every list and tab looks the same.
    Usage: @include('frontdesk.partials.pagination', ['paginator' => $entries, 'noun' => 'entry'])
--}}
@php
    // Page numbers: first, last and two either side of the current page.
    $current = $paginator->currentPage();
    $last = max(1, $paginator->lastPage());
    $pages = collect(range(1, $last))->filter(fn ($p) => $p === 1 || $p === $last || abs($p - $current) <= 2)->values();
    $box = 'flex h-8 min-w-[32px] items-center justify-center rounded-button border px-sm font-semibold transition';
@endphp
<div class="mt-md flex flex-col gap-sm border-t border-border pt-md text-metadata text-text-secondary sm:flex-row sm:items-center sm:justify-between">
    <span>Showing {{ $paginator->total() ? $paginator->firstItem().'–'.$paginator->lastItem() : 0 }} of {{ $paginator->total() }} {{ \Illuminate\Support\Str::plural($noun, $paginator->total()) }}</span>

    <nav class="flex items-center gap-xs" aria-label="Pages">
        @if ($paginator->onFirstPage())
            <span class="{{ $box }} border-border text-text-secondary/40" aria-hidden="true">‹</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="{{ $box }} border-border hover:bg-background" aria-label="Previous page">‹</a>
        @endif

        @foreach ($pages as $i => $page)
            @if ($i > 0 && $page - $pages[$i - 1] > 1)
                <span class="px-xs">…</span>
            @endif
            @if ($page === $current)
                <span class="{{ $box }} border-primary-teal bg-primary-teal text-white" aria-current="page">{{ $page }}</span>
            @else
                <a href="{{ $paginator->url($page) }}" class="{{ $box }} border-border text-text-primary hover:bg-background">{{ $page }}</a>
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="{{ $box }} border-border hover:bg-background" aria-label="Next page">›</a>
        @else
            <span class="{{ $box }} border-border text-text-secondary/40" aria-hidden="true">›</span>
        @endif
    </nav>
</div>
