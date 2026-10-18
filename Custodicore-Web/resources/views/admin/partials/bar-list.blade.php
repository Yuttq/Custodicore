{{--
    Small labelled bars, like the "Motivation" bars on a persona card, but drawn from real counts.
    Usage: @include('admin.partials.bar-list', ['title' => 'Visits by Status', 'rows' => ['Completed' => 4, 'No Show' => 1], 'empty' => 'No visits yet.'])
    Each bar's width is that row's share of the biggest row, so the longest bar is always full.
--}}
@php
    $rows = $rows ?? [];
    $max = $rows ? max(1, max($rows)) : 1;
@endphp
<div>
    <p class="text-card-title text-primary-navy">{{ $title }}</p>
    @if (empty($rows))
        <p class="mt-sm text-metadata text-text-secondary">{{ $empty ?? 'Nothing to show yet.' }}</p>
    @else
        <div class="mt-sm space-y-sm">
            @foreach ($rows as $label => $count)
                <div>
                    <div class="flex items-baseline justify-between gap-sm text-metadata">
                        <span class="text-text-primary">{{ $label }}</span>
                        <span class="font-semibold text-text-primary">{{ $count }}</span>
                    </div>
                    <div class="mt-xs h-2 w-full overflow-hidden rounded-chip bg-primary-navy/10">
                        <div class="h-full rounded-chip bg-primary-teal" style="width: {{ round($count / $max * 100) }}%"></div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
