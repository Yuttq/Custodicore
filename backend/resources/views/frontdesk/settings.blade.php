@extends('layouts.frontdesk')

@section('title', 'Settings')

@section('content')

    {{-- =========================================================
        PAGE HEADER
    ========================================================== --}}
    <div>
        <p class="text-body text-text-secondary">
            Manage your Front Desk preferences and account settings.
        </p>
    </div>


    {{-- =========================================================
        ACCOUNT INFORMATION
    ========================================================== --}}
    <section class="cc-card">

        <div class="border-b border-border pb-md">

            <h2 class="text-card-title font-semibold text-text-primary">
                Account Information
            </h2>

            <p class="mt-xs text-status-label text-text-secondary">
                Front Desk officer account details
            </p>

        </div>


        <div class="grid grid-cols-1 gap-lg pt-lg md:grid-cols-2">

            {{-- Officer Name --}}
            <div>

                <label class="text-status-label font-semibold uppercase tracking-wide text-text-secondary">
                    Officer Name
                </label>

                <input
                    type="text"
                    value="Front Desk Officer"
                    class="mt-xs w-full rounded-chip border border-border bg-background px-md py-sm text-body text-text-primary outline-none"
                >

            </div>


            {{-- Officer ID --}}
            <div>

                <label class="text-status-label font-semibold uppercase tracking-wide text-text-secondary">
                    Officer ID
                </label>

                <input
                    type="text"
                    value="BJMP-FDO-001"
                    readonly
                    class="mt-xs w-full rounded-chip border border-border bg-background px-md py-sm text-body text-text-secondary outline-none"
                >

            </div>


            {{-- Role --}}
            <div>

                <label class="text-status-label font-semibold uppercase tracking-wide text-text-secondary">
                    Role
                </label>

                <input
                    type="text"
                    value="Front Desk Officer"
                    readonly
                    class="mt-xs w-full rounded-chip border border-border bg-background px-md py-sm text-body text-text-secondary outline-none"
                >

            </div>


            {{-- Assignment --}}
            <div>

                <label class="text-status-label font-semibold uppercase tracking-wide text-text-secondary">
                    Assignment
                </label>

                <input
                    type="text"
                    value="BJMP Cebu City Jail"
                    readonly
                    class="mt-xs w-full rounded-chip border border-border bg-background px-md py-sm text-body text-text-secondary outline-none"
                >

            </div>

        </div>


        <div class="mt-lg flex justify-end">

            <button
                type="button"
                class="rounded-chip bg-primary-navy px-lg py-sm text-body font-semibold text-white transition hover:opacity-90"
                onclick="showSettingsMessage()"
            >
                Save Changes
            </button>

        </div>

    </section>


    {{-- =========================================================
        FRONT DESK PREFERENCES
    ========================================================== --}}
    <section class="cc-card">

        <div class="border-b border-border pb-md">

            <h2 class="text-card-title font-semibold text-text-primary">
                Front Desk Preferences
            </h2>

            <p class="mt-xs text-status-label text-text-secondary">
                Configure basic Front Desk display preferences.
            </p>

        </div>


        <div class="divide-y divide-border">

            {{-- Notifications --}}
            <div class="flex items-center justify-between gap-lg py-lg">

                <div>

                    <p class="text-body font-semibold text-text-primary">
                        Notifications
                    </p>

                    <p class="mt-xs text-status-label text-text-secondary">
                        Receive notifications for upcoming visitor schedules.
                    </p>

                </div>


                <label class="relative inline-flex cursor-pointer items-center">

                    <input
                        type="checkbox"
                        checked
                        class="peer sr-only"
                    >

                    <div class="h-6 w-11 rounded-full bg-border transition peer-checked:bg-primary-teal"></div>

                    <div class="absolute left-1 h-4 w-4 rounded-full bg-white transition peer-checked:translate-x-5"></div>

                </label>

            </div>


            {{-- Schedule Alerts --}}
            <div class="flex items-center justify-between gap-lg py-lg">

                <div>

                    <p class="text-body font-semibold text-text-primary">
                        Schedule Alerts
                    </p>

                    <p class="mt-xs text-status-label text-text-secondary">
                        Show alerts for visitors approaching their scheduled time.
                    </p>

                </div>


                <label class="relative inline-flex cursor-pointer items-center">

                    <input
                        type="checkbox"
                        checked
                        class="peer sr-only"
                    >

                    <div class="h-6 w-11 rounded-full bg-border transition peer-checked:bg-primary-teal"></div>

                    <div class="absolute left-1 h-4 w-4 rounded-full bg-white transition peer-checked:translate-x-5"></div>

                </label>

            </div>


            {{-- Automatic Refresh --}}
            <div class="flex items-center justify-between gap-lg py-lg">

                <div>

                    <p class="text-body font-semibold text-text-primary">
                        Automatic Refresh
                    </p>

                    <p class="mt-xs text-status-label text-text-secondary">
                        Automatically refresh Front Desk information.
                    </p>

                </div>


                <label class="relative inline-flex cursor-pointer items-center">

                    <input
                        type="checkbox"
                        checked
                        class="peer sr-only"
                    >

                    <div class="h-6 w-11 rounded-full bg-border transition peer-checked:bg-primary-teal"></div>

                    <div class="absolute left-1 h-4 w-4 rounded-full bg-white transition peer-checked:translate-x-5"></div>

                </label>

            </div>

        </div>

    </section>


    {{-- =========================================================
        SYSTEM INFORMATION
    ========================================================== --}}
    <section class="cc-card">

        <div class="border-b border-border pb-md">

            <h2 class="text-card-title font-semibold text-text-primary">
                System Information
            </h2>

            <p class="mt-xs text-status-label text-text-secondary">
                Current CustodiCore system information.
            </p>

        </div>


        <div class="grid grid-cols-1 gap-lg pt-lg sm:grid-cols-2 lg:grid-cols-3">

            {{-- System --}}
            <div>

                <p class="text-status-label uppercase tracking-wide text-text-secondary">
                    System
                </p>

                <p class="mt-xs text-body font-semibold text-text-primary">
                    CustodiCore
                </p>

            </div>


            {{-- Module --}}
            <div>

                <p class="text-status-label uppercase tracking-wide text-text-secondary">
                    Module
                </p>

                <p class="mt-xs text-body font-semibold text-text-primary">
                    Front Desk
                </p>

            </div>


            {{-- Version --}}
            <div>

                <p class="text-status-label uppercase tracking-wide text-text-secondary">
                    Version
                </p>

                <p class="mt-xs text-body font-semibold text-text-primary">
                    1.0.0
                </p>

            </div>

        </div>

    </section>


    {{-- =========================================================
        LOGOUT
    ========================================================== --}}
    <section class="cc-card">

        <div class="flex flex-col gap-md sm:flex-row sm:items-center sm:justify-between">

            <div>

                <h2 class="text-card-title font-semibold text-text-primary">
                    Account Session
                </h2>

                <p class="mt-xs text-status-label text-text-secondary">
                    Sign out of the current Front Desk session.
                </p>

            </div>


            <form method="POST" action="{{ route('logout') }}">

                @csrf

                <button
                    type="submit"
                    class="rounded-chip border border-danger px-lg py-sm text-body font-semibold text-danger transition hover:bg-danger/10"
                >
                    Log Out
                </button>

            </form>

        </div>

    </section>


    {{-- =========================================================
        SIMPLE UI MESSAGE
    ========================================================== --}}
    <div
        id="settings-message"
        class="fixed bottom-lg right-lg hidden rounded-chip border border-border bg-card px-lg py-md shadow-lg"
    >
        <p class="text-body font-semibold text-text-primary">
            Changes saved successfully.
        </p>

        <p class="mt-xs text-status-label text-text-secondary">
            Settings are currently displayed as sample data.
        </p>
    </div>


    @push('scripts')

        <script>
            function showSettingsMessage() {

                const message = document.getElementById('settings-message');

                if (!message) {
                    return;
                }

                message.classList.remove('hidden');

                setTimeout(function () {
                    message.classList.add('hidden');
                }, 3000);

            }
        </script>

    @endpush

@endsection