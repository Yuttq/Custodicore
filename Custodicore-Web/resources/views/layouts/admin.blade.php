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
                <div class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-button bg-primary-navy text-body font-bold text-white">CC</div>
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
                <a href="{{ route('admin.settings.index') }}"
                   class="cc-nav-link {{ request()->routeIs('admin.settings.index') ? 'cc-nav-link-active' : '' }}">
                    Settings
                </a>
                <div class="flex items-center gap-[10px] border-t border-border pt-[14px]">
                    @include('admin.partials.avatar', ['name' => auth()->user()->displayName(), 'size' => 'h-8 w-8', 'color' => 'bg-primary-navy'])
                    <div class="min-w-0">
                        <p class="truncate text-body font-semibold leading-tight">{{ auth()->user()->displayName() }}</p>
                        <p class="text-[11px] text-text-secondary">{{ auth()->user()->role?->role_name }}</p>
                    </div>
                </div>
                <form method="POST" action="{{ route('logout') }}" data-confirm="Sign out of CustodiCore?">
                    @csrf
                    <button type="submit" class="w-full px-[10px] text-left text-body font-medium text-[#4b5568]">Sign out</button>
                </form>
            </div>
        </aside>

        {{-- Main column --}}
        <div class="flex min-h-screen min-w-0 flex-1 flex-col">
            {{-- Topbar: title on the left, date on the right (same as the Record Officer dashboard) --}}
            <header class="flex flex-wrap items-center gap-5 px-7 pt-[22px]">
                <h1 class="whitespace-nowrap text-page-title">@yield('title', 'Dashboard')</h1>
                <p class="ml-auto whitespace-nowrap text-body">{{ now()->format('l, j F Y') }}</p>
            </header>

            <main class="flex-1 space-y-lg px-7 py-[22px]">
                @if (session('status'))
                    <div class="cc-card border-l-4 border-l-info">
                        {{ session('status') }}
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>

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
