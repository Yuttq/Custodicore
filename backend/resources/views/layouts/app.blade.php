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
      <div class="brand-icon">🛡</div>
      <div class="brand-text">
        <div class="title">CustodiCore</div>
        <div class="subtitle">INSTITUTIONAL GUARDIAN</div>
      </div>
    </div>
    <nav class="nav">
      <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
        <span class="dot">▦</span> Dashboard
      </a>
      <a href="{{ route('pdl.index') }}" class="{{ request()->routeIs('pdl.*') ? 'active' : '' }}">
        <span class="dot">🗂</span> PDL Management
      </a>
      <a href="{{ route('visitor.index') }}" class="{{ request()->routeIs('visitor.*') ? 'active' : '' }}">
        <span class="dot">👥</span> Visitor Management
      </a>
      <a href="{{ route('eligibility.index') }}" class="{{ request()->routeIs('eligibility.*') ? 'active' : '' }}">
        <span class="dot">✅</span> Eligibility Review
      </a>
      {{-- Still a placeholder until visitor tables are confirmed --}}
      <a href="{{ route('custody-history.index') }}" class="{{ request()->routeIs('custody-history.*') ? 'active' : '' }}">
        <span class="dot">⟳</span> Custody History
      </a>
      <a href="{{ route('visitation-tracking.index') }}" class="{{ request()->routeIs('visitation-tracking.*') ? 'active' : '' }}">
        <span class="dot">🗓</span> Visitation Tracking
      </a>
    </nav>
    <div class="sidebar-bottom">
      <a href="#" class="support-link">❓ Support</a>
      <div class="sidebar-footer">
        <div class="avatar">👤</div>
        <div class="who">
          {{--
            CONFIRMED GAP: auth()->user() currently returns Laravel's
            default User model (the `users` table), which has no `role` or
            display-name relationship at all — that lives on
            accounts/staff_profiles instead, and nothing connects the two
            yet (see the comment on App\Models\User and PdlController's
            currentStaffId()). This will just silently show the fallback
            text below until real auth is wired to accounts/staff_profiles.
          --}}
          <div class="name">{{ auth()->user()->name ?? 'Officer' }}</div>
          <div class="role">Records Officer</div>
        </div>
        {{--
          NOTE: no login/logout system exists in this project yet — no
          Breeze/Jetstream, no `logout` route. This is a placeholder until
          real auth is built (same gap as everywhere else auth is involved).
        --}}
        <a href="#" class="logout" title="Log out (not wired up yet)">⏻</a>
      </div>
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
            <div style="font-size:42px;margin-bottom:14px;">{{ $isError ? '⚠️' : '✅' }}</div>
            <h2 style="margin:0 0 10px 0;font-size:17px;">{{ $isError ? 'Something went wrong' : 'Success' }}</h2>
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
</body>
</html>