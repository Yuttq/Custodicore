{{--
    Pagination footer: "Showing 1–10 of 23 records" plus Previous / page numbers / Next.
    Pass the paginator made by App\Support\Pages::of():
    @include('admin.partials.pagination-footer', ['paginator' => $rows, 'label' => 'accounts'])
--}}
@php
    $label = $label ?? 'records';
    $paginator = $paginator ?? null;

    $total = $paginator ? $paginator->total() : ($total ?? 0);
    $from = $paginator ? ($paginator->firstItem() ?? 0) : ($total > 0 ? 1 : 0);
    $to = $paginator ? ($paginator->lastItem() ?? 0) : $total;
    $current = $paginator ? $paginator->currentPage() : 1;
    $last = $paginator ? $paginator->lastPage() : 1;

    // First, last and the pages next to the current one; "…" fills the gaps.
    $pages = collect([1, $last, $current - 1, $current, $current + 1])
        ->filter(fn ($p) => $p >= 1 && $p <= $last)->unique()->sort()->values();

    $edge = 'flex h-8 items-center justify-center rounded-sm border border-border px-sm text-status-label';
    $num = 'flex h-8 w-8 items-center justify-center rounded-sm border border-border text-status-label text-text-primary hover:bg-background';
@endphp
<div class="mt-md flex flex-col gap-sm border-t border-border pt-md text-metadata text-text-secondary sm:flex-row sm:items-center sm:justify-between">
    <span>Showing {{ $total > 0 ? $from . '–' . $to : '0' }} of {{ $total }} {{ $label }}</span>
    <div class="flex items-center gap-xs">
        @if ($current > 1)
            <a href="{{ $paginator->url($current - 1) }}" class="{{ $edge }} text-text-primary hover:bg-background">Previous</a>
        @else
            <button type="button" disabled class="{{ $edge }} text-text-secondary/40">Previous</button>
        @endif

        @foreach ($pages as $i => $p)
            @if ($i > 0 && $p - $pages[$i - 1] > 1)
                <span class="px-xs text-text-secondary">…</span>
            @endif
            @if ($p === $current)
                <span class="flex h-8 w-8 items-center justify-center rounded-sm bg-primary-green text-status-label font-semibold text-white">{{ $p }}</span>
            @else
                <a href="{{ $paginator->url($p) }}" class="{{ $num }}">{{ $p }}</a>
            @endif
        @endforeach

        @if ($current < $last)
            <a href="{{ $paginator->url($current + 1) }}" class="{{ $edge }} text-text-primary hover:bg-background">Next</a>
        @else
            <button type="button" disabled class="{{ $edge }} text-text-secondary/40">Next</button>
        @endif
    </div>
</div>
