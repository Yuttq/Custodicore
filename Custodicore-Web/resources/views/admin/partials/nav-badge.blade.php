{{--
  One Admin sidebar badge (App\Services\AdminNavBadges), always blue.
  Clicking the badge (not the menu name) opens data-href: the items it counts.
    $badge — ['value' => int, 'title' => string, 'url' => ?string] or null
--}}
@if ($badge ?? null)
<span class="cc-admin-badge" title="{{ $badge['title'] }}" aria-label="{{ $badge['title'] }}" @if ($badge['url'] ?? null) data-href="{{ $badge['url'] }}" @endif>{{ $badge['value'] > 99 ? '99+' : $badge['value'] }}</span>
@endif
