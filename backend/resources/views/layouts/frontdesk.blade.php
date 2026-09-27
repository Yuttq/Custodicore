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

        {{-- =========================================================
            FRONT DESK SIDEBAR
        ========================================================== --}}
        <aside class="hidden w-64 shrink-0 flex-col border-r border-border bg-primary-navy text-white lg:flex">

            {{-- =====================================================
                LOGO / SYSTEM NAME
            ====================================================== --}}
            <div class="flex items-center gap-sm px-lg py-lg">

                <div class="flex h-10 w-10 items-center justify-center rounded-sm bg-white/10 text-card-title font-bold">
                    CC
                </div>

                <div>
                    <p class="text-card-title font-bold leading-tight">
                        CustodiCore
                    </p>

                    <p class="text-status-label uppercase tracking-wide text-white/60">
                        BJMP Front Desk
                    </p>
                </div>

            </div>


            {{-- =====================================================
                MAIN NAVIGATION
            ====================================================== --}}
            <nav class="flex-1 space-y-xs px-sm py-md">

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


                @foreach ($links as $link)

                    @php
                        $routeExists = \Illuminate\Support\Facades\Route::has($link['route']);
                        $isActive = $routeExists && request()->routeIs($link['route']);
                    @endphp


                    @if ($routeExists)

                        {{-- Active / Available Navigation Item --}}
                        <a
                            href="{{ route($link['route']) }}"
                            class="flex items-center rounded-sm border-l-2 px-md py-sm text-body transition
                            {{ $isActive
                                ? 'border-l-primary-teal bg-white/15 font-semibold text-white'
                                : 'border-l-transparent text-white/75 hover:bg-white/10 hover:text-white' }}"
                        >

                            <span class="flex items-center gap-sm">

                                @include('admin.partials.icon', [
                                    'name' => $link['icon'],
                                    'class' => 'h-5 w-5 shrink-0'
                                ])

                                <span>
                                    {{ $link['label'] }}
                                </span>

                            </span>

                        </a>

                    @else

                        {{-- Future Module / Route Not Yet Created --}}
                        <div
                            class="flex cursor-not-allowed items-center rounded-sm border-l-2 border-l-transparent px-md py-sm text-body text-white/35"
                            title="This module is not available yet"
                        >

                            <span class="flex items-center gap-sm">

                                @include('admin.partials.icon', [
                                    'name' => $link['icon'],
                                    'class' => 'h-5 w-5 shrink-0'
                                ])

                                <span>
                                    {{ $link['label'] }}
                                </span>

                            </span>

                        </div>

                    @endif

                @endforeach

            </nav>


            {{-- =====================================================
                SIDEBAR SETTINGS
            ====================================================== --}}
            <div class="border-t border-white/10 px-md py-md">

                @if (\Illuminate\Support\Facades\Route::has('frontdesk.settings.index'))

                    <a
                        href="{{ route('frontdesk.settings.index') }}"
                        class="flex items-center rounded-sm border-l-2 px-md py-sm text-body transition
                        {{ request()->routeIs('frontdesk.settings.index')
                            ? 'border-l-primary-teal bg-white/15 font-semibold text-white'
                            : 'border-l-transparent text-white/75 hover:bg-white/10 hover:text-white' }}"
                    >

                        <span class="flex items-center gap-sm">

                            @include('admin.partials.icon', [
                                'name' => 'cog',
                                'class' => 'h-5 w-5 shrink-0'
                            ])

                            <span>
                                Settings
                            </span>

                        </span>

                    </a>

                @else

                    <div
                        class="flex cursor-not-allowed items-center rounded-sm border-l-2 border-l-transparent px-md py-sm text-body text-white/35"
                        title="Settings module is not available yet"
                    >

                        <span class="flex items-center gap-sm">

                            @include('admin.partials.icon', [
                                'name' => 'cog',
                                'class' => 'h-5 w-5 shrink-0'
                            ])

                            <span>
                                Settings
                            </span>

                        </span>

                    </div>

                @endif

            </div>

        </aside>


        {{-- =========================================================
            MAIN COLUMN
        ========================================================== --}}
        <div class="flex min-h-screen flex-1 flex-col">


            {{-- =====================================================
                TOPBAR
            ====================================================== --}}
            <header class="flex items-center justify-between border-b border-border bg-card px-lg py-md">

                {{-- =================================================
                    PAGE TITLE
                ================================================== --}}
                <div>
                    <h1 class="text-page-title">
                        @yield('title', 'Dashboard')
                    </h1>
                </div>


                {{-- =================================================
                    RIGHT SIDE OF TOPBAR
                ================================================== --}}
                <div class="flex items-center gap-md">


                    {{-- =================================================
                        NOTIFICATION
                    ================================================== --}}
                    <button
                        type="button"
                        class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-chip border border-border text-text-secondary transition hover:bg-background"
                        aria-label="Notifications"
                    >

                        @include('admin.partials.icon', [
                            'name' => 'bell',
                            'class' => 'h-5 w-5'
                        ])

                        {{-- Notification Indicator --}}
                        <span class="absolute right-2 top-2 h-2 w-2 rounded-full bg-danger"></span>

                    </button>


                    {{-- =================================================
                        OFFICER PROFILE
                    ================================================== --}}
                    <div class="relative">

                        <button
                            type="button"
                            id="frontdesk-profile-button"
                            class="flex items-center gap-sm rounded-chip px-sm py-xs transition hover:bg-background"
                            aria-expanded="false"
                            aria-haspopup="true"
                        >

                            {{-- Officer Name --}}
                            <div class="hidden text-right sm:block">

                                <p class="text-body font-semibold leading-tight">
                                    Front Desk Officer
                                </p>

                                <p class="text-status-label uppercase text-text-secondary">
                                    BJMP Front Desk
                                </p>

                            </div>


                            {{-- Avatar --}}
                            @include('admin.partials.avatar', [
                                'name' => 'Front Desk Officer',
                                'size' => 'h-10 w-10',
                                'color' => 'bg-primary-navy'
                            ])


                            {{-- Dropdown Arrow --}}
                            @include('admin.partials.icon', [
                                'name' => 'chevron-down',
                                'class' => 'h-4 w-4 text-text-secondary'
                            ])

                        </button>


                        {{-- =================================================
                            PROFILE DROPDOWN
                        ================================================== --}}
                        <div
                            id="frontdesk-profile-menu"
                            class="absolute right-0 z-50 mt-sm hidden w-52 rounded-sm border border-border bg-card py-xs shadow-lg"
                        >

                            {{-- Settings --}}
                            @if (\Illuminate\Support\Facades\Route::has('frontdesk.settings.index'))

                                <a
                                    href="{{ route('frontdesk.settings.index') }}"
                                    class="flex items-center gap-sm px-md py-sm text-body text-text-primary transition hover:bg-background"
                                >

                                    @include('admin.partials.icon', [
                                        'name' => 'cog',
                                        'class' => 'h-4 w-4 text-text-secondary'
                                    ])

                                    <span>
                                        Settings
                                    </span>

                                </a>

                            @endif


                            {{-- Divider --}}
                            @if (
                                \Illuminate\Support\Facades\Route::has('frontdesk.settings.index') &&
                                \Illuminate\Support\Facades\Route::has('logout')
                            )
                                <div class="my-xs border-t border-border"></div>
                            @endif


                            {{-- Logout --}}
                            @if (\Illuminate\Support\Facades\Route::has('logout'))

                                <form method="POST" action="{{ route('logout') }}">

                                    @csrf

                                    <button
                                        type="submit"
                                        class="flex w-full items-center gap-sm px-md py-sm text-left text-body text-danger transition hover:bg-background"
                                    >

                                        @include('admin.partials.icon', [
                                            'name' => 'logout',
                                            'class' => 'h-4 w-4'
                                        ])

                                        <span>
                                            Log Out
                                        </span>

                                    </button>

                                </form>

                            @endif

                        </div>

                    </div>

                </div>

            </header>


            {{-- =========================================================
                PAGE CONTENT
            ========================================================== --}}
            <main class="flex-1 space-y-lg px-lg py-lg">

                {{-- =====================================================
                    SESSION STATUS
                ====================================================== --}}
                @if (session('status'))

                    <div class="cc-card border-l-4 border-l-info">
                        {{ session('status') }}
                    </div>

                @endif


                {{-- =====================================================
                    PAGE CONTENT
                ====================================================== --}}
                @yield('content')

            </main>

        </div>

    </div>


    {{-- =============================================================
        PROFILE DROPDOWN SCRIPT
    ============================================================== --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            const button = document.getElementById('frontdesk-profile-button');
            const menu = document.getElementById('frontdesk-profile-menu');

            if (!button || !menu) {
                return;
            }


            // =========================================================
            // OPEN / CLOSE PROFILE MENU
            // =========================================================
            button.addEventListener('click', function (event) {

                event.stopPropagation();

                const isHidden = menu.classList.contains('hidden');

                menu.classList.toggle('hidden');

                button.setAttribute(
                    'aria-expanded',
                    isHidden ? 'true' : 'false'
                );

            });


            // =========================================================
            // CLOSE WHEN CLICKING OUTSIDE
            // =========================================================
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