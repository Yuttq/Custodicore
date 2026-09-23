{{--
    Small inline icon set used across the admin dashboard (sidebar nav, stat
    cards, topbar, tables). Hand-drawn generic geometric glyphs — no external
    icon library / font dependency, so it works offline and needs no npm
    package. Usage: @include('admin.partials.icon', ['name' => 'users'])
--}}
@php
    $class = $class ?? 'h-5 w-5';

    $icons = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5" /><rect x="14" y="3" width="7" height="7" rx="1.5" /><rect x="3" y="14" width="7" height="7" rx="1.5" /><rect x="14" y="14" width="7" height="7" rx="1.5" />',

        'users' => '<circle cx="9" cy="8" r="3" /><path d="M4 20c0-3 2.2-5.5 5-5.5s5 2.5 5 5.5" /><circle cx="17" cy="9" r="2.3" /><path d="M14.7 20c.2-2.3 1.7-4.1 3.5-4.6" />',

        'identification' => '<rect x="3" y="5" width="18" height="14" rx="2" /><circle cx="8.5" cy="12" r="2" /><path d="M13 10h6M13 14h4" />',

        'visitor' => '<circle cx="12" cy="8" r="3.5" /><path d="M5 20c0-3.6 3.1-6.2 7-6.2s7 2.6 7 6.2" /><path d="M9.5 8l1.7 1.7L15 6.2" />',

        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2" /><path d="M3 10h18M8 3v4M16 3v4" /><circle cx="8" cy="14.5" r="1" /><circle cx="12" cy="14.5" r="1" /><circle cx="16" cy="14.5" r="1" />',

        'qrcode' => '<rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14.5" y="14.5" width="2.2" height="2.2" /><rect x="18.8" y="14.5" width="2.2" height="2.2" /><rect x="14.5" y="18.8" width="2.2" height="2.2" /><rect x="18.8" y="18.8" width="2.2" height="2.2" />',

        'shield-check' => '<path d="M12 3l7 3v5.5c0 4.6-3 7.7-7 9-4-1.3-7-4.4-7-9V6l7-3Z" /><path d="M9 12.2l2 2 4-4.2" />',

        'clipboard' => '<rect x="6" y="4" width="12" height="17" rx="1.5" /><rect x="9" y="2.3" width="6" height="3" rx="1" /><path d="M9 11h6M9 14.2h6M9 17.4h4" />',

        'cog' => '<circle cx="12" cy="12" r="3.2" /><path d="M12 3.5v2.3M12 18.2v2.3M4.6 4.6l1.6 1.6M17.8 17.8l1.6 1.6M3.5 12h2.3M18.2 12h2.3M4.6 19.4l1.6-1.6M17.8 6.2l1.6-1.6" />',

        'bell' => '<path d="M6.5 17h11M8.3 17v-6.2a3.7 3.7 0 0 1 7.4 0V17" /><path d="M10.3 20a1.9 1.9 0 0 0 3.4 0" />',

        'search' => '<circle cx="10" cy="10" r="6" /><path d="M20 20l-5.3-5.3" />',

        'chevron-down' => '<path d="M6 9l6 6 6-6" />',
        'chevron-left' => '<path d="M15 6l-6 6 6 6" />',
        'chevron-right' => '<path d="M9 6l6 6-6 6" />',

        'trend-up' => '<path d="M3 17l5.5-5.5 3.5 3.5L20 7" /><path d="M14.5 7H20v5.5" />',

        'warning' => '<path d="M12 3.2L21.5 20h-19L12 3.2Z" /><path d="M12 9.5v4.2" /><circle cx="12" cy="17" r="0.6" fill="currentColor" stroke="none" />',

        'check-circle' => '<circle cx="12" cy="12" r="9" /><path d="M8 12.3l2.7 2.7L16 9.3" />',

        'clock' => '<circle cx="12" cy="12" r="9" /><path d="M12 7.3v5l3.5 2" />',

        'reports' => '<path d="M6.5 3h8l3.5 3.5V21h-11.5Z" /><path d="M9 13.5v4M12.3 10.3v7.2M15.5 15.5v2" />',

        'plus' => '<path d="M12 5v14M5 12h14" />',

        'x' => '<path d="M6 6l12 12M18 6L6 18" />',
    ];

    $body = $icons[$name] ?? $icons['dashboard'];
@endphp
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="{{ $class }}" aria-hidden="true">
    {!! $body !!}
</svg>
