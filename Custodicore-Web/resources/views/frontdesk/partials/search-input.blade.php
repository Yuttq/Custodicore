{{--
    Front Desk search box (magnifying glass + text), the same on every list.
    Put it inside the page's GET form; Enter submits.
    Usage: @include('frontdesk.partials.search-input', ['id' => 'scheduleQuery', 'value' => $query, 'placeholder' => 'Search visitor or PDL…'])
--}}
<label for="{{ $id }}" class="sr-only">{{ $placeholder }}</label>
<div class="relative">
    <span class="pointer-events-none absolute left-sm top-1/2 -translate-y-1/2 text-text-secondary">
        @include('admin.partials.icon', ['name' => 'search', 'class' => 'h-4 w-4'])
    </span>
    <input type="search" id="{{ $id }}" name="{{ $name ?? 'q' }}" value="{{ $value }}" maxlength="100" autocomplete="off"
           placeholder="{{ $placeholder }}" class="cc-input !mt-0 w-[240px] pl-[34px]" />
</div>
