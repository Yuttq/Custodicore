{{--
    Static pagination footer (visual only — no real paging logic yet, since
    everything on this page is still sample data). Usage:
    @include('admin.partials.pagination-footer', ['total' => count($rows), 'label' => 'accounts'])
--}}
@php
    $total = $total ?? 0;
    $label = $label ?? 'records';
@endphp
<div class="mt-md flex flex-col gap-sm border-t border-border pt-md text-metadata text-text-secondary sm:flex-row sm:items-center sm:justify-between">
    <span>Showing {{ $total > 0 ? '1–' . $total : '0' }} of {{ $total }} {{ $label }}</span>
    <div class="flex items-center gap-xs">
        <button type="button" disabled
                class="flex h-8 w-8 items-center justify-center rounded-sm border border-border text-text-secondary/40">
            @include('admin.partials.icon', ['name' => 'chevron-left', 'class' => 'h-4 w-4'])
        </button>
        <span class="flex h-8 w-8 items-center justify-center rounded-sm bg-primary-navy text-status-label font-semibold text-white">1</span>
        <button type="button" disabled
                class="flex h-8 w-8 items-center justify-center rounded-sm border border-border text-text-secondary/40">
            @include('admin.partials.icon', ['name' => 'chevron-right', 'class' => 'h-4 w-4'])
        </button>
    </div>
</div>
