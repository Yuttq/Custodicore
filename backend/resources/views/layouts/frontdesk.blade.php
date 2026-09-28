<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('title', 'Dashboard') · CustodiCore</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-background text-text-primary antialiased">

<div class="flex min-h-screen w-full">

    {{-- =========================================================
        SIDEBAR
    ========================================================== --}}
    <aside class="hidden w-64 shrink-0 flex-col bg-primary-navy text-white lg:flex">

        {{-- LOGO --}}
        <div class="flex h-20 shrink-0 items-center gap-3 border-b border-white/10 px-5">

            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white/10 font-bold">
                CC
            </div>

            <div class="min-w-0">
                <p class="text-base font-bold leading-tight">
                    CustodiCore
                </p>

                <p class="mt-0.5 text-[10px] uppercase tracking-wider text-white/60">
                    BJMP Front Desk
                </p>
            </div>

        </div>


        {{-- NAVIGATION --}}
        <nav class="flex-1 overflow-y-auto px-3 py-5">

            <p class="mb-3 px-3 text-[10px] font-semibold uppercase tracking-widest text-white/40">
                Front Desk
            </p>

            @php
                $links = [
                    [
                        'route' => 'frontdesk.dashboard',
                        'label' => 'Dashboard',
                        'icon' => 'dashboard',
                    ],
                    [
                        'route' => 'frontdesk.checkin-checkout',
                        'label' => 'Check-In / Check-Out',
                        'icon' => 'qrcode',
                    ],
                    [
                        'route' => 'frontdesk.schedule',
                        'label' => "Today's Schedule",
                        'icon' => 'calendar',
                    ],
                    [
                        'route' => 'frontdesk.visitor-lookup',
                        'label' => 'Visitor Lookup',
                        'icon' => 'visitor',
                    ],
                ];
            @endphp


            <div class="space-y-1">

                @foreach ($links as $link)

                    @php
                        $routeExists = \Illuminate\Support\Facades\Route::has($link['route']);
                        $isActive = $routeExists && request()->routeIs($link['route']);
                    @endphp

                    @if ($routeExists)

                        <a
                            href="{{ route($link['route']) }}"
                            class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition
                            {{ $isActive
                                ? 'bg-white/15 font-semibold text-white'
                                : 'text-white/70 hover:bg-white/10 hover:text-white' }}"
                        >

                            @include('admin.partials.icon', [
                                'name' => $link['icon'],
                                'class' => 'h-5 w-5 shrink-0'
                            ])

                            <span>
                                {{ $link['label'] }}
                            </span>

                        </a>

                    @else

                        <div
                            class="flex cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-white/30"
                            title="This module is not available yet"
                        >

                            @include('admin.partials.icon', [
                                'name' => $link['icon'],
                                'class' => 'h-5 w-5 shrink-0'
                            ])

                            <span>
                                {{ $link['label'] }}
                            </span>

                        </div>

                    @endif

                @endforeach

            </div>

        </nav>


        {{-- SIDEBAR BOTTOM --}}
        <div class="shrink-0 border-t border-white/10 p-3">

            @if (\Illuminate\Support\Facades\Route::has('frontdesk.settings.index'))

                <a
                    href="{{ route('frontdesk.settings.index') }}"
                    class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-white/70 transition hover:bg-white/10 hover:text-white"
                >

                    @include('admin.partials.icon', [
                        'name' => 'cog',
                        'class' => 'h-5 w-5 shrink-0'
                    ])

                    <span>
                        Settings
                    </span>

                </a>

            @endif

        </div>

    </aside>


    {{-- =========================================================
        MAIN AREA
    ========================================================== --}}
    <div class="flex min-w-0 flex-1 flex-col">


        {{-- =====================================================
            TOP BAR
        ====================================================== --}}
        <header class="flex h-16 shrink-0 items-center justify-end border-b border-border bg-white px-4 sm:px-6">

            <div class="flex items-center gap-3 sm:gap-4">

                {{-- NOTIFICATION --}}
                <button
                    type="button"
                    class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-border text-text-secondary transition hover:bg-background"
                    aria-label="Notifications"
                >

                    @include('admin.partials.icon', [
                        'name' => 'bell',
                        'class' => 'h-5 w-5'
                    ])

                    <span class="absolute right-2 top-2 h-2 w-2 rounded-full bg-danger"></span>

                </button>


                {{-- PROFILE --}}
                <div class="relative">

                    <button
                        type="button"
                        id="frontdesk-profile-button"
                        class="flex items-center gap-2 rounded-lg px-2 py-1 transition hover:bg-background"
                        aria-expanded="false"
                        aria-haspopup="true"
                    >

                        <div class="hidden text-right sm:block">

                            <p class="text-sm font-semibold leading-tight text-text-primary">
                                Front Desk Officer
                            </p>

                            <p class="text-[10px] uppercase tracking-wide text-text-secondary">
                                BJMP Front Desk
                            </p>

                        </div>


                        @include('admin.partials.avatar', [
                            'name' => 'Front Desk Officer',
                            'size' => 'h-10 w-10',
                            'color' => 'bg-primary-navy'
                        ])


                        @include('admin.partials.icon', [
                            'name' => 'chevron-down',
                            'class' => 'h-4 w-4 text-text-secondary'
                        ])

                    </button>


                    {{-- PROFILE MENU --}}
                    <div
                        id="frontdesk-profile-menu"
                        class="absolute right-0 z-50 mt-2 hidden w-52 rounded-lg border border-border bg-white py-1 shadow-lg"
                    >

                        @if (\Illuminate\Support\Facades\Route::has('frontdesk.settings.index'))

                            <a
                                href="{{ route('frontdesk.settings.index') }}"
                                class="flex items-center gap-2 px-4 py-2 text-sm text-text-primary hover:bg-background"
                            >

                                @include('admin.partials.icon', [
                                    'name' => 'cog',
                                    'class' => 'h-4 w-4 text-text-secondary'
                                ])

                                Settings

                            </a>

                        @endif


                        @if (\Illuminate\Support\Facades\Route::has('logout'))

                            <div class="my-1 border-t border-border"></div>

                            <form method="POST" action="{{ route('logout') }}">

                                @csrf

                                <button
                                    type="submit"
                                    class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-danger hover:bg-background"
                                >

                                    @include('admin.partials.icon', [
                                        'name' => 'logout',
                                        'class' => 'h-4 w-4'
                                    ])

                                    Log Out

                                </button>

                            </form>

                        @endif

                    </div>

                </div>

            </div>

        </header>


        {{-- =====================================================
            PAGE CONTENT
        ====================================================== --}}
        <main class="min-w-0 flex-1 overflow-x-hidden">

            @if (session('status'))

                <div class="px-4 pt-4 sm:px-6 lg:px-8">
                    <div class="cc-card border-l-4 border-l-info">
                        {{ session('status') }}
                    </div>
                </div>

            @endif

            @yield('content')

        </main>

    </div>

</div>


{{-- =============================================================
    PROFILE DROPDOWN
============================================================= --}}
<script>
document.addEventListener('DOMContentLoaded', function () {

    const button = document.getElementById('frontdesk-profile-button');
    const menu = document.getElementById('frontdesk-profile-menu');

    if (!button || !menu) {
        return;
    }

    button.addEventListener('click', function (event) {

        event.stopPropagation();

        const hidden = menu.classList.contains('hidden');

        menu.classList.toggle('hidden');

        button.setAttribute(
            'aria-expanded',
            hidden ? 'true' : 'false'
        );

    });

    document.addEventListener('click', function (event) {

        if (
            !menu.contains(event.target) &&
            !button.contains(event.target)
        ) {

            menu.classList.add('hidden');

            button.setAttribute(
                'aria-expanded',
                'false'
            );

        }

    });

});
</script>


@stack('scripts')

</body>
</html>