{{--
    Stat card with an icon chip, big value and an optional colored hint line.
    Usage: @include('admin.partials.stat-card', ['label' => '...', 'value' => '...', 'hint' => '...', 'icon' => 'users', 'accent' => 'info'])
    'accent' must be one of: info | success | warning | danger (see tailwind.config.js safelist).
--}}
@php
    $accent = $accent ?? 'info';
    $icon = $icon ?? 'dashboard';

    // Full class names written out (not built from $accent) so Tailwind can see them.
    $tone = [
        'info'    => ['chip' => 'bg-[#E0EAFF] text-info',    'hint' => 'text-info'],
        'success' => ['chip' => 'bg-[#DCFCE7] text-success', 'hint' => 'text-success'],
        'warning' => ['chip' => 'bg-[#FEF3C7] text-[#D97706]', 'hint' => 'text-[#D97706]'],
        'danger'  => ['chip' => 'bg-[#FEE2E2] text-danger',  'hint' => 'text-danger'],
    ][$accent] ?? ['chip' => 'bg-[#E0EAFF] text-info', 'hint' => 'text-info'];
@endphp
<div class="cc-card flex items-center gap-md !p-[18px_20px]" style="box-shadow: 0 12px 28px rgba(15, 61, 122, 0.14);">
    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-[18px] {{ $tone['chip'] }}">
        @include('admin.partials.icon', ['name' => $icon, 'class' => 'h-7 w-7'])
    </div>
    <div class="min-w-0">
        <p class="truncate text-section-label uppercase text-text-secondary">{{ $label }}</p>
        <p class="text-[32px] font-bold leading-tight text-primary-navy">{{ $value }}</p>
        @if (!empty($hint))
            <p class="text-metadata font-semibold {{ $tone['hint'] }}">{{ $hint }}</p>
        @endif
    </div>
</div>
