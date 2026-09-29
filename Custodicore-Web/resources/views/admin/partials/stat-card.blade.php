{{--
    Stat card with an icon chip, big value and an optional colored hint line.
    Usage: @include('admin.partials.stat-card', ['label' => '...', 'value' => '...', 'hint' => '...', 'icon' => 'users', 'accent' => 'info'])
    'accent' must be one of: info | success | warning | danger (see tailwind.config.js safelist).
--}}
@php
    $accent = $accent ?? 'info';
    $icon = $icon ?? 'dashboard';
@endphp
<div class="cc-card">
    <div class="flex items-start justify-between gap-sm">
        <div class="min-w-0">
            <p class="truncate text-section-label text-text-secondary">{{ $label }}</p>
            <p class="mt-xs text-page-title">{{ $value }}</p>
            @if (!empty($hint))
                <p class="mt-xs text-metadata text-{{ $accent }}">{{ $hint }}</p>
            @endif
        </div>
        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-chip bg-{{ $accent }}/10 text-{{ $accent }}">
            @include('admin.partials.icon', ['name' => $icon, 'class' => 'h-6 w-6'])
        </div>
    </div>
</div>
