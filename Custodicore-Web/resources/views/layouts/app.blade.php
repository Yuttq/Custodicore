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
      <div class="brand-icon">CC</div>
      <div class="brand-text">
        <div class="title">CustodiCore</div>
        <div class="subtitle">INSTITUTIONAL GUARDIAN</div>
      </div>
    </div>
    <nav class="nav">
      <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
        Dashboard
      </a>
      <a href="{{ route('pdl.index') }}" class="{{ request()->routeIs('pdl.*') ? 'active' : '' }}">
        PDL Management
      </a>
      <a href="{{ route('cell-blocks.index') }}" class="{{ request()->routeIs('cell-blocks.*') ? 'active' : '' }}">
        Cell Blocks
      </a>
      <a href="{{ route('visitor.index') }}" class="{{ request()->routeIs('visitor.*') ? 'active' : '' }}">
        Visitor Management
      </a>
      <a href="{{ route('eligibility.index') }}" class="{{ request()->routeIs('eligibility.*') ? 'active' : '' }}">
        Eligibility Review
      </a>
      {{-- Still a placeholder until visitor tables are confirmed --}}
      <a href="{{ route('custody-history.index') }}" class="{{ request()->routeIs('custody-history.*') ? 'active' : '' }}">
        Custody History
      </a>
      <a href="{{ route('visitation-tracking.index') }}" class="{{ request()->routeIs('visitation-tracking.*') ? 'active' : '' }}">
        Visitation Tracking
      </a>
    </nav>
    <div class="sidebar-bottom">
      <a href="#" class="support-link">Support</a>
      <div class="sidebar-footer">
        <div class="avatar">{{ strtoupper(mb_substr(trim(auth()->user()?->displayName() ?? 'O'), 0, 1)) }}</div>
        <div class="who">
          <div class="name">{{ auth()->user()?->displayName() ?? 'Officer' }}</div>
          <div class="role">{{ auth()->user()?->role?->role_name ?? 'Records Officer' }}</div>
        </div>
      </div>
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
</body>
</html>