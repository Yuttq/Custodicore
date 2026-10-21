{{--
  A visitor's photo (taken in the mobile app), or their initial when none yet.
    $visitor — VisitorProfile
    $class   — wrapper class (default "thumb"; "visitor-photo-lg" on profiles)
--}}
@php $class = $class ?? 'thumb'; @endphp
@if ($visitor && ($url = $visitor->photoUrl()))
  <img class="{{ $class }} pdl-photo-img" src="{{ $url }}" alt="Photo of {{ $visitor->full_name }}" loading="lazy">
@else
  <div class="{{ $class }} visitor-no-photo" aria-hidden="true" title="No photo yet">{{ $visitor?->initial() ?? '?' }}</div>
@endif
