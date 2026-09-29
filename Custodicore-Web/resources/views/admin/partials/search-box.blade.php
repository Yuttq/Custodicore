{{--
    Visual-only search input (not wired to anything yet — no data source to
    search against until the dashboard is connected to the database).
    Usage: @include('admin.partials.search-box', ['placeholder' => 'Search PDLs…'])
--}}
@php
    $placeholder = $placeholder ?? 'Search…';
@endphp
<div class="relative w-full sm:w-64">
    <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-sm text-text-secondary">
        @include('admin.partials.icon', ['name' => 'search', 'class' => 'h-4 w-4'])
    </span>
    <input type="text" placeholder="{{ $placeholder }}" disabled
           class="w-full rounded-button border border-border bg-white py-sm pl-xl pr-md text-body text-text-secondary placeholder:text-text-secondary/60" />
</div>
