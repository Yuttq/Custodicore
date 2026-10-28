{{--
    Small inline line-icon set for the Record Officer pages (replaces emoji).
    Inline SVG, no icon library or font, so it works offline.
    Usage: @include('partials.icon', ['name' => 'users'])   (size comes from the parent's CSS)
--}}
@php
    $icons = [
        'users' => '<circle cx="9" cy="8" r="3" /><path d="M4 20c0-3 2.2-5.5 5-5.5s5 2.5 5 5.5" /><circle cx="17" cy="9" r="2.3" /><path d="M14.7 20c.2-2.3 1.7-4.1 3.5-4.6" />',
        'user' => '<circle cx="12" cy="8" r="3.5" /><path d="M5 20c0-3.6 3.1-6.2 7-6.2s7 2.6 7 6.2" />',
        'user-minus' => '<circle cx="10" cy="8" r="3.5" /><path d="M3.5 20c0-3.6 3-6.2 6.5-6.2s6.5 2.6 6.5 6.2" /><path d="M17 11h5" />',
        'transfer' => '<path d="M4 8h14M14 4l4 4-4 4" /><path d="M20 16H6M10 12l-4 4 4 4" />',
        'unlock' => '<rect x="5" y="11" width="14" height="9" rx="2" /><path d="M8 11V7a4 4 0 0 1 7.5-2" />',
        'calendar-x' => '<rect x="3" y="5" width="18" height="16" rx="2" /><path d="M3 10h18M8 3v4M16 3v4" /><path d="M10 14l4 4M14 14l-4 4" />',
        'clipboard' => '<rect x="6" y="4" width="12" height="17" rx="1.5" /><rect x="9" y="2.3" width="6" height="3" rx="1" /><path d="M9 11h6M9 14.2h6M9 17.4h4" />',
        'scale' => '<path d="M12 4v16M7 20h10M5 8h14" /><path d="M5 8l-2.5 6a3 3 0 0 0 5 0L5 8ZM19 8l-2.5 6a3 3 0 0 0 5 0L19 8Z" />',
        'building' => '<rect x="5" y="3" width="14" height="18" rx="1.5" /><path d="M9 7h2M13 7h2M9 11h2M13 11h2M10 21v-4h4v4" />',
        'camera' => '<path d="M4 8h3l1.5-2h7L17 8h3v11H4Z" /><circle cx="12" cy="13" r="3.5" />',
        'clock' => '<circle cx="12" cy="12" r="9" /><path d="M12 7.3v5l3.5 2" />',
    ];
    $body = $icons[$name] ?? $icons['clipboard'];
@endphp
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{!! $body !!}</svg>
