<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Laravel CSRF Token --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Dashboard') · CustodiCore Front Desk</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-background text-text-primary">
    <div class="flex min-h-screen flex-col lg:flex-row">
        {{-- Sidebar: white, text-only buttons, teal when active (same as the Record Officer sidebar) --}}
        <aside class="cc-sidebar flex w-[220px] shrink-0 flex-col border-r border-border bg-white px-[14px] py-5">
            <div class="flex items-center gap-[10px] px-2 pb-[22px] pt-[6px]">
                <img src="{{ asset('images/bjmp-logo.png') }}" alt="BJMP logo" class="h-[38px] w-[38px] shrink-0 object-contain"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <div style="display:none;" class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-button bg-primary-navy text-body font-bold text-white">CC</div>
                <div>
                    <p class="text-card-title leading-tight text-primary-navy">CustodiCore</p>
                    <p class="text-[10px] font-semibold uppercase tracking-[0.04em] text-text-secondary">BJMP Front Desk</p>
                </div>
            </div>

            <nav class="mt-[6px] flex flex-col gap-[2px]">
                @php
                    $links = [
                        ['route' => 'frontdesk.dashboard', 'label' => 'Dashboard'],
                        ['route' => 'frontdesk.checkin-checkout', 'label' => 'Check-In / Check-Out'],
                        ['route' => 'frontdesk.walk-ins.index', 'label' => 'Walk-in Visitors', 'active' => 'frontdesk.walk-ins.*'],
                        ['route' => 'frontdesk.schedule', 'label' => 'Visit Schedule'],
                        ['route' => 'frontdesk.visitor-lookup', 'label' => 'Visitor Lookup'],
                        ['route' => 'frontdesk.attendance-log', 'label' => 'Attendance Log'],
                    ];
                @endphp

                @foreach ($links as $link)
                    <a href="{{ route($link['route']) }}"
                       class="cc-nav-link {{ request()->routeIs($link['active'] ?? $link['route']) ? 'cc-nav-link-active' : '' }}">
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </nav>

            <div class="mt-auto flex flex-col gap-[14px]">
                <a href="{{ route('account.profile') }}" title="My profile and picture" class="flex items-center gap-[10px] border-t border-border pt-[14px] transition hover:opacity-80">
                    @include('admin.partials.avatar', ['name' => auth()->user()->displayName(), 'size' => 'h-8 w-8', 'color' => 'bg-primary-navy', 'photo' => auth()->user()->staffProfile?->photoUrl()])
                    <div class="min-w-0">
                        <p class="truncate text-body font-semibold leading-tight">{{ auth()->user()->displayName() }}</p>
                        <p class="text-[11px] text-text-secondary">Gate Operations</p>
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
                $isHome = request()->routeIs('frontdesk.dashboard');
                $me = auth()->user();
                $firstName = $me->staffProfile?->first_name ?: (preg_split('/\s+/', trim($me->displayName()))[0] ?? 'there');
            @endphp
            {{-- Gradient hero: date + page title (the dashboard home gets a greeting instead). --}}
            <header class="cc-hero {{ $isHome ? 'cc-hero-home' : '' }}">
                <p class="text-metadata text-white/85">{{ now()->format('l, j F Y') }}</p>
                @if ($isHome)
                    <h1 id="greeting" class="mt-xs text-[28px] font-bold leading-tight">Welcome back, {{ $firstName }}</h1>
                    <p class="mt-xs text-body text-white/90">Here is what is happening at the gate today.</p>
                @else
                    <div class="mt-xs flex flex-wrap items-center justify-between gap-sm">
                        <h1 class="text-[28px] font-bold leading-tight">@yield('title', 'Dashboard')</h1>
                        {{-- Optional page buttons, aligned with the title (e.g. "Back to …"). --}}
                        @yield('header_actions')
                    </div>
                    @hasSection('subtitle')
                        <p class="mt-xs text-body text-white/90">@yield('subtitle')</p>
                    @endif
                @endif
            </header>

            <main class="relative flex-1 space-y-lg px-8 pb-[22px] {{ $isHome ? '-mt-[72px]' : '-mt-11' }}">
                @if ($errors->any())
                    <div class="cc-card border-l-4 border-l-danger" role="alert">
                        <p class="font-semibold">Please correct the following:</p>
                        <ul class="mt-xs list-disc pl-lg text-body">
                            @foreach ($errors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
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

    <script>
        // Keeps every count on the page (dashboard cards, Visit Schedule tabs) current as visitors
        // check in and out, and flags each change on the cards (+1 / −1) for a few seconds.
        (() => {
            const url = @json(route('frontdesk.dashboard.stats'));
            const tones = { up: ['bg-[#DCFCE7]', 'text-success'], down: ['bg-[#FEE2E2]', 'text-danger'] };
            const timers = {};

            const refresh = async () => {
                if (document.hidden) return;
                try {
                    const response = await fetch(url, { headers: { Accept: 'application/json' } });
                    if (!response.ok) return;
                    const counts = await response.json();

                    Object.entries(counts).forEach(([key, value]) => {
                        const valueEls = document.querySelectorAll(`[data-stat="${key}"]`);
                        if (!valueEls.length) return;

                        const change = value - Number(valueEls[0].textContent);
                        valueEls.forEach(el => { el.textContent = value; });

                        const deltaEl = document.querySelector(`[data-stat-delta="${key}"]`);
                        if (!change || !deltaEl) return;

                        deltaEl.textContent = change > 0 ? `+${change}` : `\u2212${Math.abs(change)}`;
                        deltaEl.classList.remove('hidden', ...tones.up, ...tones.down);
                        deltaEl.classList.add(...(change > 0 ? tones.up : tones.down));
                        clearTimeout(timers[key]);
                        timers[key] = setTimeout(() => deltaEl.classList.add('hidden'), 8000);
                    });
                } catch {
                    // Offline or signed out: keep the last numbers and try again next time.
                }
            };

            setInterval(refresh, 10000);
            document.addEventListener('visibilitychange', refresh);
        })();
    </script>

    {{-- Short messages: shown at the top right, gone after 5 seconds. --}}
    <div id="ccToasts" class="pointer-events-none fixed right-6 top-6 z-50 flex w-[360px] max-w-[calc(100vw-48px)] flex-col gap-sm" aria-live="polite">
        @if (session('status'))
            <div data-toast="info" class="hidden">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div data-toast="danger" class="hidden">{{ session('error') }}</div>
        @endif
    </div>
    <script>
        // window.ccToast(message, tone): tone is info | success | warning | danger.
        (() => {
            const box = document.getElementById('ccToasts');
            const tones = {
                info: 'border-l-info', success: 'border-l-success', warning: 'border-l-warning', danger: 'border-l-danger',
            };
            window.ccToast = (message, tone = 'info') => {
                const toast = document.createElement('div');
                toast.setAttribute('role', tone === 'danger' ? 'alert' : 'status');
                toast.className = `pointer-events-auto cc-card border-l-4 ${tones[tone] ?? tones.info} text-body shadow-card transition-opacity duration-500`;
                toast.textContent = message;
                box.appendChild(toast);
                setTimeout(() => { toast.style.opacity = '0'; }, 5000);
                setTimeout(() => toast.remove(), 5500);
            };
            box.querySelectorAll('[data-toast]').forEach(el => { window.ccToast(el.textContent.trim(), el.dataset.toast); el.remove(); });
        })();
    </script>

    @stack('scripts')
</body>
</html>
