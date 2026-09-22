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
        {{-- Sidebar --}}
        <aside class="hidden w-64 shrink-0 flex-col border-r border-border bg-primary-navy text-white lg:flex">
            <div class="flex items-center gap-sm px-lg py-lg">
                <div class="flex h-10 w-10 items-center justify-center rounded-sm bg-white/10 text-card-title font-bold">CC</div>
                <div>
                    <p class="text-card-title font-bold leading-tight">CustodiCore</p>
                    <p class="text-status-label uppercase tracking-wide text-white/60">BJMP Admin</p>
                </div>
            </div>

            <nav class="flex-1 space-y-xs px-sm py-md">
                @php
                    $links = [
                        ['route' => 'admin.dashboard', 'label' => 'Dashboard', 'module' => '1.7'],
                        ['route' => 'admin.users.index', 'label' => 'User Management', 'module' => '1.1'],
                        ['route' => 'admin.pdls.index', 'label' => 'PDL Management', 'module' => '1.2'],
                        ['route' => 'admin.visitors.index', 'label' => 'Visitor Management', 'module' => '1.3'],
                        ['route' => 'admin.schedules.index', 'label' => 'Visit Scheduling', 'module' => '1.4'],
                        ['route' => 'admin.checkins.index', 'label' => 'QR Check-In / Check-Out', 'module' => '1.5'],
                        ['route' => 'admin.eligibility.index', 'label' => 'Eligibility Assessment', 'module' => '1.6'],
                        ['route' => 'admin.audit.index', 'label' => 'Audit Trail & Reports', 'module' => '1.7'],
                    ];
                @endphp

                @foreach ($links as $link)
                    <a href="{{ route($link['route']) }}"
                       class="flex items-center justify-between rounded-sm px-md py-sm text-body transition
                              {{ request()->routeIs($link['route']) ? 'bg-white/15 font-semibold text-white' : 'text-white/75 hover:bg-white/10 hover:text-white' }}">
                        <span>{{ $link['label'] }}</span>
                        <span class="text-status-label text-white/40">{{ $link['module'] }}</span>
                    </a>
                @endforeach
            </nav>

            <div class="border-t border-white/10 px-md py-md">
                <a href="{{ route('admin.settings.index') }}"
                   class="flex items-center justify-between rounded-sm px-md py-sm text-body transition
                          {{ request()->routeIs('admin.settings.index') ? 'bg-white/15 font-semibold text-white' : 'text-white/75 hover:bg-white/10 hover:text-white' }}">
                    <span>Settings</span>
                </a>
            </div>
        </aside>

        {{-- Main column --}}
        <div class="flex min-h-screen flex-1 flex-col">
            {{-- Topbar --}}
            <header class="flex items-center justify-between border-b border-border bg-card px-lg py-md">
                <div>
                    <h1 class="text-page-title">@yield('title', 'Dashboard')</h1>
                    @hasSection('subtitle')
                        <p class="text-metadata text-text-secondary">@yield('subtitle')</p>
                    @endif
                </div>
                <div class="flex items-center gap-sm">
                    <div class="text-right">
                        <p class="text-body font-semibold leading-tight">Ana R. Domingo</p>
                        <p class="text-status-label uppercase text-text-secondary">System Administrator / Warden</p>
                    </div>
                    <div class="flex h-10 w-10 items-center justify-center rounded-chip bg-primary-navy text-body font-semibold text-white">AD</div>
                </div>
            </header>

            <main class="flex-1 space-y-lg px-lg py-lg">
                @if (session('status'))
                    <div class="cc-card border-l-4 border-l-info">
                        {{ session('status') }}
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>
</body>
</html>
