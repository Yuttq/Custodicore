<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') · CustodiCore Admin</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-background text-text-primary">
    <div class="flex min-h-screen">
        {{-- Sidebar: white, text-only buttons, teal when active (same as the Record Officer sidebar) --}}
        <aside class="hidden w-[220px] shrink-0 flex-col border-r border-border bg-white px-[14px] py-5 lg:flex">
            <div class="flex items-center gap-[10px] px-2 pb-[22px] pt-[6px]">
                <img src="{{ asset('images/bjmp-logo.png') }}" alt="BJMP logo" class="h-[38px] w-[38px] shrink-0 object-contain"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <div style="display:none;" class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-button bg-primary-navy text-body font-bold text-white">CC</div>
                <div>
                    <p class="text-card-title leading-tight text-primary-navy">CustodiCore</p>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.04em] text-text-secondary">BJMP Admin</p>
                </div>
            </div>

            <nav class="mt-[6px] flex flex-col gap-[2px]">
                @php
                    $links = [
                        ['route' => 'admin.dashboard', 'label' => 'Dashboard'],
                        ['route' => 'admin.users.index', 'label' => 'User Management'],
                        ['route' => 'admin.pdls.index', 'label' => 'PDL Management'],
                        ['route' => 'admin.visitors.index', 'label' => 'Visitor Management'],
                        ['route' => 'admin.schedules.index', 'label' => 'Visit Scheduling'],
                        ['route' => 'admin.checkins.index', 'label' => 'Check-In / Check-Out'],
                        ['route' => 'admin.audit.index', 'label' => 'Audit Trail'],
                    ];
                @endphp

                @foreach ($links as $link)
                    <a href="{{ route($link['route']) }}"
                       class="cc-nav-link {{ request()->routeIs($link['route']) ? 'cc-nav-link-active' : '' }}">
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </nav>

            <div class="mt-auto flex flex-col gap-[14px]">
                <a href="{{ route('account.profile') }}" title="My profile and picture" class="flex items-center gap-[10px] border-t border-border pt-[14px] transition hover:opacity-80">
                    @include('admin.partials.avatar', ['name' => auth()->user()->displayName(), 'size' => 'h-8 w-8', 'color' => 'bg-primary-navy', 'photo' => auth()->user()->staffProfile?->photoUrl()])
                    <div class="min-w-0">
                        <p class="truncate text-body font-semibold leading-tight">{{ auth()->user()->displayName() }}</p>
                        <p class="text-[11px] text-text-secondary">{{ \App\Models\Role::label(auth()->user()->role?->role_name) }}</p>
                    </div>
                </a>
                <form method="POST" action="{{ route('logout') }}" data-confirm="Sign out of CustodiCore?">
                    @csrf
                    <button type="submit" class="w-full px-[10px] text-left text-body font-medium text-[#4b5568]">Sign out</button>
                </form>
            </div>
        </aside>

        {{-- Main column --}}
        <div class="flex min-h-screen min-w-0 flex-1 flex-col">
            @php
                $isHome = request()->routeIs('admin.dashboard');
                $me = auth()->user();
                $firstName = $me->staffProfile?->first_name ?: (preg_split('/\s+/', trim($me->displayName()))[0] ?? 'there');
            @endphp
            {{-- Gradient hero: date + page title (the dashboard home gets a greeting instead). --}}
            <header class="cc-hero {{ $isHome ? 'cc-hero-home' : '' }}">
                <p class="text-metadata text-white/85">{{ now()->format('l, j F Y') }}</p>
                @if ($isHome)
                    <h1 id="greeting" class="mt-xs text-[28px] font-bold leading-tight">Welcome back, {{ $firstName }}</h1>
                    <p class="mt-xs text-body text-white/90">Here is what is happening at the facility today.</p>
                @else
                    <h1 class="mt-xs text-[28px] font-bold leading-tight">@yield('title', 'Dashboard')</h1>
                @endif
            </header>

            <main class="relative flex-1 space-y-lg px-8 pb-[22px] {{ $isHome ? '-mt-[72px]' : '-mt-11' }}">
                @if (session('status'))
                    <div class="cc-card border-l-4 border-l-info">
                        {{ session('status') }}
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>

    @if ($isHome ?? false)
        <script>
            // Greeting by the visitor's own clock (the server may be in another timezone).
            (function () {
                var h = new Date().getHours();
                var g = h < 12 ? 'Good morning' : (h < 18 ? 'Good afternoon' : 'Good evening');
                var el = document.getElementById('greeting');
                if (el) { el.textContent = g + ', ' + @json($firstName); }
            })();
        </script>
    @endif

    {{-- Chart.js is bundled via resources/js/app.js (see @vite above), which
         exposes it as window.Chart. That app.js module tag is deferred, so
         any script in @stack('scripts') below must wait for
         DOMContentLoaded before touching window.Chart — see dashboard's
         script block for the pattern. --}}
    @stack('scripts')

    {{-- "Enter your password to finish" popup for forms marked data-password-confirm. --}}
    @include('partials.password-confirm', ['banner' => true])
</body>
</html>
