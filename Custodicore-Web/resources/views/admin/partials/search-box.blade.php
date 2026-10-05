{{--
    Visual-only search input (not wired to anything yet — no data source to
    search against until the dashboard is connected to the database).
    Usage: @include('admin.partials.search-box', ['placeholder' => 'Search PDLs…'])
--}}
@php
    $placeholder = $placeholder ?? 'Search…';
@endphp
<div class="relative w-full sm:w-64">
    <input type="text" placeholder="{{ $placeholder }}" disabled
           class="w-full rounded-button border border-border bg-white h-[38px] px-md text-body text-text-secondary placeholder:text-text-secondary/60" />
</div>
