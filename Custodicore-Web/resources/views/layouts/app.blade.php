<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>@yield('title', 'CustodiCore')</title>
<link rel="stylesheet" href="{{ asset('css/styles.css') }}">
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
    <nav class="nav">
      <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
        Dashboard
      </a>
      <a href="{{ route('pdl.index') }}" class="{{ request()->routeIs('pdl.*', 'visitation-tracking.*') ? 'active' : '' }}">
        PDL Management
      </a>
      <a href="{{ route('cell-blocks.index') }}" class="{{ request()->routeIs('cell-blocks.*') ? 'active' : '' }}">
        Cell Blocks
      </a>
      {{-- Count only: visitor-submitted visit requests (`assigned`) awaiting review. --}}
      @php $pendingVisitRequestCount = \App\Models\VisitRequest::awaitingReview()->count(); @endphp
      <a href="{{ route('visitor.index') }}#visit-requests" class="{{ request()->routeIs('visitor.*') ? 'active' : '' }}">
        Visitor Management
        @if ($pendingVisitRequestCount)<span class="nav-count" title="{{ $pendingVisitRequestCount }} visit {{ \Illuminate\Support\Str::plural('request', $pendingVisitRequestCount) }} awaiting review">{{ $pendingVisitRequestCount }}</span>@endif
      </a>
      @php
        // Review Queue = accounts waiting for approval + visit requests flagged for review.
        $reviewQueueCount = array_sum(\App\Services\ReviewQueue::counts());
      @endphp
      <a href="{{ route('review-queue') }}" class="{{ request()->routeIs('review-queue', 'account-reviews.*', 'eligibility.*') ? 'active' : '' }}">
        Review Queue
        @if ($reviewQueueCount)<span class="nav-count" title="{{ $reviewQueueCount }} waiting for review">{{ $reviewQueueCount }}</span>@endif
      </a>
      <a href="{{ route('upcoming-visits.index') }}" class="{{ request()->routeIs('upcoming-visits.*') ? 'active' : '' }}">
        Upcoming Visits
      </a>
      <a href="{{ route('visitor-slots.index') }}" class="{{ request()->routeIs('visitor-slots.*') ? 'active' : '' }}">
        Manage Visitor Slots
      </a>
      <a href="{{ route('activity-log.index') }}" class="{{ request()->routeIs('activity-log.*') ? 'active' : '' }}">
        Activity Log
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
      <form method="POST" action="{{ route('logout') }}" style="margin:0;">
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
</body>
</html>