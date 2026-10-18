{{--
    Initials avatar. Usage: @include('admin.partials.avatar', ['name' => $full_name])
    Optional: 'photo' (URL of a picture; shown instead of initials), 'size' (Tailwind h-/w- classes, default h-9 w-9), 'color' (an explicit
    bg-* class to override the auto-picked palette color, e.g. for the signed-in user).
--}}
@php
    $name = trim((string) ($name ?? ''));
    $size = $size ?? 'h-9 w-9';
    $photo = $photo ?? null;

    // Brand/neutral colors only — success/warning/danger are reserved for
    // status meaning (shared UI guideline), so an avatar never looks like an alert.
    $palette = ['bg-primary-navy', 'bg-primary-teal', 'bg-info'];

    if (!empty($color)) {
        $bg = $color;
    } else {
        $seed = $name !== '' ? array_sum(array_map('ord', str_split($name))) : 0;
        $bg = $palette[$seed % count($palette)];
    }

    $parts = $name !== '' ? preg_split('/\s+/', $name) : [];
    $first = $parts[0][0] ?? '?';
    $last = count($parts) > 1 ? $parts[count($parts) - 1][0] : ($parts[0][1] ?? '');
    $initials = strtoupper($first . $last);
@endphp
@if ($photo)
    <img src="{{ $photo }}" alt="" class="{{ $size }} shrink-0 rounded-chip object-cover">
@else
    <div class="{{ $size }} {{ $bg }} flex shrink-0 items-center justify-center rounded-chip text-status-label font-semibold text-white">
        {{ $initials }}
    </div>
@endif
