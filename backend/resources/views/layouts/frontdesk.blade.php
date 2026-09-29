<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Dashboard') · CustodiCore Front Desk</title>
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
                    <p class="text-status-label uppercase tracking-wide text-white/60">BJMP Front Desk</p>
                </div>
            </div>

            <nav class="flex-1 space-y-xs px-sm py-md">
                @php
                    $links = [
                        ['route' => 'frontdesk.dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard'],
                        ['route' => 'frontdesk.checkin-checkout', 'label' => 'Check-In / Check-Out', 'icon' => 'qrcode'],
                        ['route' => 'frontdesk.schedule', 'label' => "Today's Schedule", 'icon' => 'calendar'],
                        ['route' => 'frontdesk.visitor-lookup', 'label' => 'Visitor Lookup', 'icon' => 'visitor'],
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
                <a href="{{ route('frontdesk.settings.index') }}"
                   class="flex items-center rounded-sm border-l-2 px-md py-sm text-body transition
                          {{ request()->routeIs('frontdesk.settings.index') ? 'border-l-primary-teal bg-white/15 font-semibold text-white' : 'border-l-transparent text-white/75 hover:bg-white/10 hover:text-white' }}">
                    <span class="flex items-center gap-sm">
                        @include('admin.partials.icon', ['name' => 'cog', 'class' => 'h-5 w-5 shrink-0'])
                        <span>Settings</span>
                    </span>
                </a>
            </div>
        </aside>

        {{-- Main column --}}
        <div class="flex min-h-screen flex-1 flex-col">
            <header class="flex items-center justify-between border-b border-border bg-card px-lg py-md">
                <div>
                    <h1 class="text-page-title">@yield('title', 'Dashboard')</h1>
                </div>
                <div class="flex items-center gap-md">
                    <div class="flex items-center gap-sm">
                        <div class="hidden text-right sm:block">
                            <p class="text-body font-semibold leading-tight">{{ auth()->user()->displayName() }}</p>
                            <p class="text-status-label uppercase text-text-secondary">Gate Operations</p>
                        </div>
                        @include('admin.partials.avatar', ['name' => auth()->user()->displayName(), 'size' => 'h-10 w-10', 'color' => 'bg-primary-navy'])
                        <form method="POST" action="{{ route('logout') }}" data-confirm="Sign out of CustodiCore?">
                            @csrf
                            <button type="submit" title="Sign out"
                                    class="flex h-10 w-10 items-center justify-center rounded-chip border border-border text-text-secondary transition hover:bg-background">
                                @include('admin.partials.icon', ['name' => 'logout', 'class' => 'h-4 w-4'])
                            </button>
                        </form>
                    </div>
                </div>
            </header>

            <main class="flex-1 space-y-lg px-lg py-lg">
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
