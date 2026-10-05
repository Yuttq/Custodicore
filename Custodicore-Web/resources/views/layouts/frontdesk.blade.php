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
    <div class="flex min-h-screen">
        {{-- Sidebar: white, text-only buttons, teal when active (same as the Record Officer sidebar) --}}
        <aside class="hidden w-[220px] shrink-0 flex-col border-r border-border bg-white px-[14px] py-5 lg:flex">
            <div class="flex items-center gap-[10px] px-2 pb-[22px] pt-[6px]">
                <div class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-button bg-primary-navy text-body font-bold text-white">CC</div>
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
                        ['route' => 'frontdesk.schedule', 'label' => "Today's Schedule"],
                        ['route' => 'frontdesk.visitor-lookup', 'label' => 'Visitor Lookup'],
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
                <a href="{{ route('frontdesk.settings.index') }}"
                   class="cc-nav-link {{ request()->routeIs('frontdesk.settings.index') ? 'cc-nav-link-active' : '' }}">
                    Settings
                </a>
                <div class="flex items-center gap-[10px] border-t border-border pt-[14px]">
                    @include('admin.partials.avatar', ['name' => auth()->user()->displayName(), 'size' => 'h-8 w-8', 'color' => 'bg-primary-navy'])
                    <div class="min-w-0">
                        <p class="truncate text-body font-semibold leading-tight">{{ auth()->user()->displayName() }}</p>
                        <p class="text-[11px] text-text-secondary">Gate Operations</p>
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
                @if (session('error'))
                    <div class="cc-card border-l-4 border-l-danger">
                        {{ session('error') }}
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>

    @stack('scripts')
</body>
</html>
