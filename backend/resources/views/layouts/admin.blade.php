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
                        ['route' => 'admin.dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard'],
                        ['route' => 'admin.users.index', 'label' => 'User Management', 'icon' => 'users'],
                        ['route' => 'admin.pdls.index', 'label' => 'PDL Management', 'icon' => 'identification'],
                        ['route' => 'admin.visitors.index', 'label' => 'Visitor Management', 'icon' => 'visitor'],
                        ['route' => 'admin.schedules.index', 'label' => 'Visit Scheduling', 'icon' => 'calendar'],
                        ['route' => 'admin.checkins.index', 'label' => 'Check-In / Check-Out', 'icon' => 'qrcode'],
                        ['route' => 'admin.audit.index', 'label' => 'Audit Trail', 'icon' => 'clipboard'],
                    ];
                @endphp

                @foreach ($links as $link)
                    <a href="{{ route($link['route']) }}"
                       class="flex items-center rounded-sm border-l-2 px-md py-sm text-body transition
                              {{ request()->routeIs($link['route']) ? 'border-l-primary-teal bg-white/15 font-semibold text-white' : 'border-l-transparent text-white/75 hover:bg-white/10 hover:text-white' }}">
                        <span class="flex items-center gap-sm">
                            @include('admin.partials.icon', ['name' => $link['icon'], 'class' => 'h-5 w-5 shrink-0'])
                            <span>{{ $link['label'] }}</span>
                        </span>
                    </a>
                @endforeach
            </nav>

            <div class="border-t border-white/10 px-md py-md">
                <a href="{{ route('admin.settings.index') }}"
                   class="flex items-center rounded-sm border-l-2 px-md py-sm text-body transition
                          {{ request()->routeIs('admin.settings.index') ? 'border-l-primary-teal bg-white/15 font-semibold text-white' : 'border-l-transparent text-white/75 hover:bg-white/10 hover:text-white' }}">
                    <span class="flex items-center gap-sm">
                        @include('admin.partials.icon', ['name' => 'cog', 'class' => 'h-5 w-5 shrink-0'])
                        <span>Settings</span>
                    </span>
                </a>
            </div>
        </aside>

        {{-- Main column --}}
        <div class="flex min-h-screen flex-1 flex-col">
            {{-- Topbar --}}
            <header class="flex items-center justify-between border-b border-border bg-card px-lg py-md">
                <div>
                    <h1 class="text-page-title">@yield('title', 'Dashboard')</h1>
                </div>
                <div class="flex items-center gap-md">
                    <div class="relative hidden md:block">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-sm text-text-secondary">
                            @include('admin.partials.icon', ['name' => 'search', 'class' => 'h-4 w-4'])
                        </span>
                        <input type="text" placeholder="Search PDLs, visitors, records…" disabled
                               class="w-56 rounded-button border border-border bg-background py-sm pl-xl pr-md text-body text-text-secondary placeholder:text-text-secondary/60 xl:w-72" />
                    </div>

                    <button type="button"
                            class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-chip border border-border text-text-secondary transition hover:bg-background">
                        @include('admin.partials.icon', ['name' => 'bell', 'class' => 'h-5 w-5'])
                        <span class="absolute right-2 top-2 h-2 w-2 rounded-full bg-danger"></span>
                    </button>

                    <div class="flex items-center gap-sm">
                        <div class="hidden text-right sm:block">
                            <p class="text-body font-semibold leading-tight">Ana R. Domingo</p>
                            <p class="text-status-label uppercase text-text-secondary">System Administrator / Warden</p>
                        </div>
                        @include('admin.partials.avatar', ['name' => 'Ana R. Domingo', 'size' => 'h-10 w-10', 'color' => 'bg-primary-navy'])
                        @include('admin.partials.icon', ['name' => 'chevron-down', 'class' => 'h-4 w-4 text-text-secondary'])
                    </div>
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

    {{-- Chart.js is bundled via resources/js/app.js (see @vite above), which
         exposes it as window.Chart. That app.js module tag is deferred, so
         any script in @stack('scripts') below must wait for
         DOMContentLoaded before touching window.Chart — see dashboard's
         script block for the pattern. --}}
    @stack('scripts')
</body>
</html>
