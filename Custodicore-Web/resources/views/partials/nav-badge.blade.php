{{--
  One sidebar badge (App\Services\NavBadges), always blue: a count = waiting
  for a decision or information, "!" = something to fix.
  Clicking the badge (not the menu name) opens data-href: the items it counts.
    $badge — ['kind' => count|warn|info, 'value' => int, 'title' => string, 'url' => ?string] or null
--}}
@if ($badge ?? null)
@php
  $class = ['warn' => 'nav-count is-warn', 'info' => 'nav-count is-info'][$badge['kind']] ?? 'nav-count';
  $link = ($badge['url'] ?? null) ? ' data-href="' . e($badge['url']) . '"' : '';
  $label = $badge['kind'] === 'warn' ? ' aria-label="' . e($badge['title']) . '"' : '';
@endphp
<span class="{{ $class }}" title="{{ $badge['title'] }}"{!! $label . $link !!}>{{ $badge['kind'] === 'warn' ? '!' : $badge['value'] }}</span>
@endif
