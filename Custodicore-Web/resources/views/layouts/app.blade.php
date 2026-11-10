<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>@yield('title', 'CustodiCore')</title>
{{-- ?v= changes whenever the file does, so browsers and caches fetch the new styles after a deploy. --}}
<link rel="stylesheet" href="{{ asset('css/styles.css') }}?v={{ @filemtime(public_path('css/styles.css')) ?: '1' }}">
@include('partials.favicon')
</head>
<body>
<div class="app">

  <aside class="sidebar">
    <div class="brand">
      <img src="{{ asset('images/bjmp-logo.png') }}" alt="BJMP logo" style="width:38px;height:38px;object-fit:contain;flex-shrink:0;"
           onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
      <div class="brand-icon" style="display:none;">CC</div>
      <div class="brand-text">
        <div class="title">CustodiCore</div>
        <div class="subtitle">INSTITUTIONAL GUARDIAN</div>
      </div>
    </div>
    @include('partials.menu-toggle', ['class' => 'menu-toggle'])
    {{-- Badges (NavBadges service): what still needs doing; refreshed every minute below. --}}
    @php $navBadges = app(\App\Services\NavBadges::class)->all(); @endphp
    <nav class="nav" data-nav-badges-url="{{ route('nav-badges') }}">
      <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
        Dashboard
      </a>
      <a href="{{ route('pdl.index') }}" data-nav="pdls" class="{{ request()->routeIs('pdl.*', 'visitation-tracking.*') ? 'active' : '' }}">
        PDL Records
        @include('partials.nav-badge', ['badge' => $navBadges['pdls']])
      </a>
      <a href="{{ route('cell-blocks.index') }}" data-nav="cells" class="{{ request()->routeIs('cell-blocks.*') ? 'active' : '' }}">
        Cell Blocks
        @include('partials.nav-badge', ['badge' => $navBadges['cells']])
      </a>
      <a href="{{ route('visitor.index') }}#visit-requests" data-nav="visitors" class="{{ request()->routeIs('visitor.*') ? 'active' : '' }}">
        Visitor Records
        @include('partials.nav-badge', ['badge' => $navBadges['visitors']])
      </a>
      <a href="{{ route('review-queue') }}" data-nav="review" class="{{ request()->routeIs('review-queue', 'account-reviews.*', 'eligibility.*') ? 'active' : '' }}">
        Review Queue
        @include('partials.nav-badge', ['badge' => $navBadges['review']])
      </a>
      <a href="{{ route('upcoming-visits.index') }}" data-nav="upcoming" class="{{ request()->routeIs('upcoming-visits.*') ? 'active' : '' }}">
        Upcoming Visits
        @include('partials.nav-badge', ['badge' => $navBadges['upcoming']])
      </a>
      <a href="{{ route('visitor-slots.index') }}" data-nav="slots" class="{{ request()->routeIs('visitor-slots.*') ? 'active' : '' }}">
        Manage Visitor Slots
        @include('partials.nav-badge', ['badge' => $navBadges['slots']])
      </a>
      <a href="{{ route('activity-log.index') }}" class="{{ request()->routeIs('activity-log.*') ? 'active' : '' }}">
        Activity History
      </a>
    </nav>
    <div class="sidebar-bottom">
      <a href="#" class="support-link">Support</a>
      <a href="{{ route('account.profile') }}" class="sidebar-footer" title="My profile and picture" style="text-decoration:none;color:inherit;">
        @php $myPhoto = auth()->user()?->staffProfile?->photoUrl(); @endphp
        @if ($myPhoto)
          <img src="{{ $myPhoto }}" alt="" class="avatar" style="object-fit:cover;">
        @else
          <div class="avatar">{{ strtoupper(mb_substr(trim(auth()->user()?->displayName() ?? 'O'), 0, 1)) }}</div>
        @endif
        <div class="who">
          <div class="name">{{ auth()->user()?->displayName() ?? 'Officer' }}</div>
          <div class="role">{{ \App\Models\Role::label(auth()->user()?->role?->role_name) ?: 'Records Officer' }}</div>
        </div>
      </a>
      <form method="POST" action="{{ route('logout') }}" style="margin:0;" data-confirm="Sign out of CustodiCore?" data-confirm-title="Sign out" data-confirm-ok="Sign out">
        @csrf
        <button type="submit" class="support-link" style="width:100%;text-align:left;background:none;border:none;cursor:pointer;padding:0;font:inherit;color:inherit;">
          Sign out
        </button>
      </form>
    </div>
  </aside>

  <main class="main">
    @if (session('success') || session('error') || $errors->any())
      @php
        $isError = session('error') || $errors->any();
      @endphp
      <div class="modal-overlay open" id="flashModal" onclick="if(event.target===this) this.remove()">
        <div class="modal modal-narrow">
          <div class="modal-body" style="text-align:center;padding:36px 28px 28px 28px;">
            <h2 style="margin:0 0 10px 0;font-size:17px;color:{{ $isError ? 'var(--red-text)' : 'var(--green)' }};">{{ $isError ? 'Something went wrong' : 'Success' }}</h2>
            <div style="color:var(--muted);font-size:13.5px;line-height:1.5;margin-bottom:26px;">
              @if (session('success'))
                {{ session('success') }}
              @endif
              @if (session('error'))
                {{ session('error') }}
              @endif
              @if ($errors->any())
                <ul style="text-align:left;margin:0;padding-left:18px;">
                  @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                  @endforeach
                </ul>
              @endif
            </div>
            <button type="button" class="btn btn-primary" style="min-width:100px;justify-content:center;"
                    onclick="document.getElementById('flashModal').remove()">
              OK
            </button>
          </div>
        </div>
      </div>
      <script>
        document.addEventListener('keydown', function onFlashEscape(e) {
          if (e.key === 'Escape') {
            const modal = document.getElementById('flashModal');
            if (modal) modal.remove();
            document.removeEventListener('keydown', onFlashEscape);
          }
        });
      </script>
    @endif

    @yield('content')
  </main>

</div>

{{-- "Enter your password to finish" popup for forms marked data-password-confirm.
     This layout's flash modal already lists $errors, so no extra banner. --}}
@include('partials.password-confirm', ['banner' => false])

{{-- "Are you sure?" popup for forms marked data-confirm. --}}
@include('partials.action-confirm')

{{-- Back to top: appears once the page is scrolled down. --}}
<button type="button" class="to-top" id="toTop" aria-label="Back to top" title="Back to top">↑</button>
<script>
  (function () {
    const btn = document.getElementById('toTop');
    const toggle = function () { btn.classList.toggle('is-visible', window.scrollY > 400); };
    window.addEventListener('scroll', toggle, { passive: true });
    btn.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
    toggle();
  })();
</script>
{{-- Sidebar badges stay live without reloading: refreshed every 15 seconds and whenever the
     tab or window comes back into view. A badge disappears as soon as its count reaches zero.
     If the section you are on changes (new items, or items handled by someone else), a notice
     offers a reload; the page itself is only reloaded when you choose to. --}}
<div class="live-notice" data-live-notice hidden role="status">
  <span data-live-notice-text></span>
  <button type="button" class="live-notice-reload" data-live-notice-reload>Reload</button>
  <button type="button" class="live-notice-close" data-live-notice-close aria-label="Dismiss">×</button>
</div>
<script>
  (function () {
    var nav = document.querySelector('[data-nav-badges-url]');
    if (!nav) return;
    var notice = document.querySelector('[data-live-notice]');
    var noticeText = notice.querySelector('[data-live-notice-text]');
    var dismissedAt = null;

    // A badge's count: every badge title starts with it ("3 waiting for review").
    function countOf(link) {
      var badge = link && link.querySelector('.nav-count');
      return badge ? (parseInt(badge.getAttribute('title'), 10) || 1) : 0;
    }

    // The section you are on, and its count when the page loaded.
    var current = nav.querySelector('a.active[data-nav]');
    var loadedCount = countOf(current);

    function showNotice(count) {
      if (!current || count === loadedCount || count === dismissedAt) { notice.hidden = true; return; }
      noticeText.textContent = count > loadedCount
        ? 'New items are waiting here.'
        : 'Some items here were already handled by another officer.';
      notice.hidden = false;
    }

    function refresh() {
      if (document.hidden) return;
      fetch(nav.dataset.navBadgesUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (badges) {
          if (!badges) return;
          Object.keys(badges).forEach(function (key) {
            var link = nav.querySelector('[data-nav="' + key + '"]');
            if (!link) return;
            var old = link.querySelector('.nav-count');
            if (old) old.remove();
            if (badges[key]) link.insertAdjacentHTML('beforeend', badges[key]);
          });
          showNotice(countOf(current));
        })
        .catch(function () {});
    }

    // Clicking a badge opens the items it counts (the menu name still opens the full page).
    nav.addEventListener('click', function (e) {
      var badge = e.target.closest('.nav-count[data-href]');
      if (!badge) return;
      e.preventDefault();
      location.href = badge.dataset.href;
    });

    notice.querySelector('[data-live-notice-reload]').addEventListener('click', function () { location.reload(); });
    notice.querySelector('[data-live-notice-close]').addEventListener('click', function () {
      dismissedAt = countOf(current);
      notice.hidden = true;
    });

    setInterval(refresh, 15000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
    window.addEventListener('focus', refresh);
    // Coming back with the browser's Back button shows a saved copy of the page: bring it up to date.
    window.addEventListener('pageshow', function (e) { if (e.persisted) refresh(); });
  })();
</script>
</body>
</html>