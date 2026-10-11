{{--
  PDL photo, or their initials when no photo has been uploaded.
    $pdl   — Pdl
    $class — wrapper class (default "thumb"; "pdl-avatar" on the dashboard,
             "pdl-photo-lg" on the profile header)
--}}
@php $class = $class ?? 'thumb'; @endphp
@if ($url = $pdl->photoUrl())
  <img class="{{ $class }} pdl-photo-img" src="{{ $url }}" alt="Photo of {{ $pdl->full_name }}" loading="lazy">
@else
  <div class="{{ $class }}" aria-hidden="true">{{ $pdl->initials() }}</div>
@endif
