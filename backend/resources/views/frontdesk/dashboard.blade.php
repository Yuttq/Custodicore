@extends('layouts.frontdesk')

@section('title', 'Dashboard')

@section('content')

<div class="mx-auto w-full max-w-[1440px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">


    {{-- =========================================================
        PAGE HEADER
    ========================================================== --}}
    <div class="mb-7 flex flex-col gap-5 border-b border-border pb-6 xl:flex-row xl:items-end xl:justify-between">

        <div class="min-w-0">

            <p class="mb-1 text-xs font-semibold uppercase tracking-wider text-primary-teal">
                Front Desk Operations
            </p>

            <h1 class="text-2xl font-bold tracking-tight text-primary-navy sm:text-3xl">
                Front Desk Dashboard
            </h1>

            <p class="mt-1 text-sm text-text-secondary">
                Monitor today's visitor activity and scheduled visits.
            </p>

        </div>


        {{-- DATE + TIME --}}
        <div class="flex shrink-0 items-center gap-5 sm:gap-6">

            <div class="text-left xl:text-right">

                <p class="text-[10px] font-semibold uppercase tracking-wider text-text-secondary">
                    Today
                </p>

                <p
                    id="current-date"
                    class="mt-1 whitespace-nowrap text-sm font-semibold text-text-primary"
                >
                    —
                </p>

            </div>


            <div class="h-10 w-px bg-border"></div>


            <div class="text-left">

                <p class="text-[10px] font-semibold uppercase tracking-wider text-text-secondary">
                    Current Time
                </p>

                <p
                    id="current-time"
                    class="mt-1 whitespace-nowrap text-lg font-bold text-primary-navy"
                >
                    —
                </p>

            </div>

        </div>

    </div>



    {{-- =========================================================
        SESSION OVERVIEW
    ========================================================== --}}
    <section class="mb-7">

        <div class="mb-3 flex items-center justify-between">

            <div>

                <h2 class="text-base font-semibold text-text-primary">
                    Today's Session Overview
                </h2>

                <p class="mt-0.5 text-xs text-text-secondary">
                    Current visitor activity
                </p>

            </div>

        </div>


        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">


            {{-- CHECKED-IN --}}
            <div class="min-w-0 rounded-xl border border-border bg-white p-5 shadow-card">

                <div class="flex items-start justify-between gap-3">

                    <div class="min-w-0">

                        <p class="text-xs font-semibold uppercase tracking-wide text-text-secondary">
                            Checked-In Today
                        </p>

                        <p class="mt-2 text-3xl font-bold leading-none text-primary-navy">
                            0
                        </p>

                        <p class="mt-2 text-xs text-text-secondary">
                            Visitors currently inside
                        </p>

                    </div>

                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-info">

                        @include('admin.partials.icon', [
                            'name' => 'qrcode',
                            'class' => 'h-4 w-4'
                        ])

                    </div>

                </div>

            </div>


            {{-- WAITING --}}
            <div class="min-w-0 rounded-xl border border-border bg-white p-5 shadow-card">

                <div class="flex items-start justify-between gap-3">

                    <div class="min-w-0">

                        <p class="text-xs font-semibold uppercase tracking-wide text-text-secondary">
                            Waiting
                        </p>

                        <p class="mt-2 text-3xl font-bold leading-none text-primary-navy">
                            0
                        </p>

                        <p class="mt-2 text-xs text-text-secondary">
                            Visitors waiting
                        </p>

                    </div>

                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-warning">

                        @include('admin.partials.icon', [
                            'name' => 'calendar',
                            'class' => 'h-4 w-4'
                        ])

                    </div>

                </div>

            </div>


            {{-- COMPLETED --}}
            <div class="min-w-0 rounded-xl border border-border bg-white p-5 shadow-card">

                <div class="flex items-start justify-between gap-3">

                    <div class="min-w-0">

                        <p class="text-xs font-semibold uppercase tracking-wide text-text-secondary">
                            Completed
                        </p>

                        <p class="mt-2 text-3xl font-bold leading-none text-primary-navy">
                            0
                        </p>

                        <p class="mt-2 text-xs text-text-secondary">
                            Visits completed today
                        </p>

                    </div>

                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-success">

                        @include('admin.partials.icon', [
                            'name' => 'check',
                            'class' => 'h-4 w-4'
                        ])

                    </div>

                </div>

            </div>


            {{-- NO SHOW --}}
            <div class="min-w-0 rounded-xl border border-border bg-white p-5 shadow-card">

                <div class="flex items-start justify-between gap-3">

                    <div class="min-w-0">

                        <p class="text-xs font-semibold uppercase tracking-wide text-text-secondary">
                            No-Show
                        </p>

                        <p class="mt-2 text-3xl font-bold leading-none text-primary-navy">
                            0
                        </p>

                        <p class="mt-2 text-xs text-text-secondary">
                            Missed scheduled visits
                        </p>

                    </div>

                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-danger">

                        @include('admin.partials.icon', [
                            'name' => 'close',
                            'class' => 'h-4 w-4'
                        ])

                    </div>

                </div>

            </div>

        </div>

    </section>



    {{-- =========================================================
        MAIN DASHBOARD GRID
    ========================================================== --}}
    <div class="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1.7fr)_minmax(320px,0.9fr)]">


        {{-- =====================================================
            CURRENTLY CHECKED-IN
        ====================================================== --}}
        <section class="min-w-0 overflow-hidden rounded-xl border border-border bg-white shadow-card">

            <div class="flex items-center justify-between gap-4 border-b border-border px-5 py-4">

                <div class="min-w-0">

                    <h2 class="text-base font-semibold text-text-primary">
                        Currently Checked-In
                    </h2>

                    <p class="mt-1 text-xs text-text-secondary">
                        Visitors currently inside the facility
                    </p>

                </div>

                <span class="shrink-0 rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-info">
                    0 inside
                </span>

            </div>


            <div class="overflow-x-auto">

                <table class="w-full min-w-[620px] text-left">

                    <thead>

                        <tr class="border-b border-border bg-background">

                            <th class="w-[30%] px-5 py-3 text-[10px] font-semibold uppercase tracking-wide text-text-secondary">
                                Visitor Name
                            </th>

                            <th class="w-[27%] px-5 py-3 text-[10px] font-semibold uppercase tracking-wide text-text-secondary">
                                PDL Name
                            </th>

                            <th class="w-[23%] px-5 py-3 text-[10px] font-semibold uppercase tracking-wide text-text-secondary">
                                Check-In Time
                            </th>

                            <th class="w-[20%] px-5 py-3 text-[10px] font-semibold uppercase tracking-wide text-text-secondary">
                                Status
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <tr>

                            <td colspan="4" class="px-5 py-12 text-center">

                                <p class="text-sm text-text-secondary">
                                    No visitors are currently checked in.
                                </p>

                            </td>

                        </tr>

                    </tbody>

                </table>

            </div>

        </section>



        {{-- =====================================================
            RIGHT COLUMN
        ====================================================== --}}
        <div class="min-w-0 space-y-6">


            {{-- QUICK ACTIONS --}}
            <section class="rounded-xl border border-border bg-white p-5 shadow-card">

                <div class="mb-4">

                    <h2 class="text-base font-semibold text-text-primary">
                        Quick Actions
                    </h2>

                    <p class="mt-1 text-xs text-text-secondary">
                        Common front desk tasks
                    </p>

                </div>


                <div class="grid grid-cols-1 gap-2">

                    @if (\Illuminate\Support\Facades\Route::has('frontdesk.checkin-checkout'))

                        <a
                            href="{{ route('frontdesk.checkin-checkout') }}"
                            class="flex items-center justify-center rounded-lg bg-primary-navy px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-navy/90"
                        >
                            Check-In / Check-Out
                        </a>

                    @endif


                    @if (\Illuminate\Support\Facades\Route::has('frontdesk.visitor-lookup'))

                        <a
                            href="{{ route('frontdesk.visitor-lookup') }}"
                            class="flex items-center justify-center rounded-lg border border-border bg-white px-4 py-2.5 text-sm font-semibold text-text-primary transition hover:bg-background"
                        >
                            Visitor Lookup
                        </a>

                    @endif

                </div>

            </section>



            {{-- GATE STATUS --}}
            <section class="rounded-xl border border-border bg-white p-5 shadow-card">

                <div class="mb-4">

                    <h2 class="text-base font-semibold text-text-primary">
                        Gate Status
                    </h2>

                    <p class="mt-1 text-xs text-text-secondary">
                        Current visitation access
                    </p>

                </div>


                {{-- MAIN BUILDING --}}
                <div class="flex items-start justify-between gap-4 border-b border-border py-3 first:pt-0">

                    <div class="min-w-0">

                        <p class="text-sm font-semibold text-text-primary">
                            Main Building
                        </p>

                        <p class="mt-1 text-xs text-text-secondary">
                            Thursday & Saturday
                        </p>

                        <p class="mt-1 text-[11px] text-text-secondary">
                            9:00 AM–11:30 AM · 1:00 PM–4:30 PM
                        </p>

                    </div>

                    <span class="shrink-0 rounded-full bg-rose-50 px-3 py-1 text-[10px] font-semibold text-danger">
                        Closed
                    </span>

                </div>


                {{-- SECONDARY BUILDING --}}
                <div class="flex items-start justify-between gap-4 py-3">

                    <div class="min-w-0">

                        <p class="text-sm font-semibold text-text-primary">
                            Secondary Building
                        </p>

                        <p class="mt-1 text-xs text-text-secondary">
                            Friday & Sunday
                        </p>

                        <p class="mt-1 text-[11px] text-text-secondary">
                            9:00 AM–11:30 AM · 1:00 PM–4:30 PM
                        </p>

                    </div>

                    <span class="shrink-0 rounded-full bg-rose-50 px-3 py-1 text-[10px] font-semibold text-danger">
                        Closed
                    </span>

                </div>


                {{-- HOURS --}}
                <div class="mt-2 rounded-lg bg-background p-3">

                    <p class="text-[10px] font-semibold uppercase tracking-wide text-primary-navy">
                        Visitation Hours
                    </p>

                    <div class="mt-2 space-y-1 text-[11px] text-text-secondary">

                        <p>
                            <span class="font-semibold text-text-primary">
                                Morning:
                            </span>
                            9:00 AM – 11:30 AM
                        </p>

                        <p>
                            <span class="font-semibold text-text-primary">
                                Afternoon:
                            </span>
                            1:00 PM – 4:30 PM
                        </p>

                        <p>
                            <span class="font-semibold text-text-primary">
                                Break:
                            </span>
                            11:30 AM – 1:00 PM
                        </p>

                    </div>

                </div>

            </section>

        </div>

    </div>



    {{-- =========================================================
        UPCOMING SCHEDULE
    ========================================================== --}}
    <section class="mt-6 min-w-0 overflow-hidden rounded-xl border border-border bg-white shadow-card">

        <div class="flex items-center justify-between gap-4 border-b border-border px-5 py-4">

            <div class="min-w-0">

                <h2 class="text-base font-semibold text-text-primary">
                    Today's Upcoming Schedule
                </h2>

                <p class="mt-1 text-xs text-text-secondary">
                    Approved visitor schedules for today
                </p>

            </div>


            @if (\Illuminate\Support\Facades\Route::has('frontdesk.schedule'))

                <a
                    href="{{ route('frontdesk.schedule') }}"
                    class="shrink-0 text-xs font-semibold text-primary-navy hover:underline"
                >
                    View Full Schedule →
                </a>

            @endif

        </div>


        <div class="overflow-x-auto">

            <table class="w-full min-w-[700px] text-left">

                <thead>

                    <tr class="border-b border-border bg-background">

                        <th class="w-[18%] px-5 py-3 text-[10px] font-semibold uppercase tracking-wide text-text-secondary">
                            Time
                        </th>

                        <th class="w-[30%] px-5 py-3 text-[10px] font-semibold uppercase tracking-wide text-text-secondary">
                            Visitor Name
                        </th>

                        <th class="w-[32%] px-5 py-3 text-[10px] font-semibold uppercase tracking-wide text-text-secondary">
                            PDL Name
                        </th>

                        <th class="w-[20%] px-5 py-3 text-[10px] font-semibold uppercase tracking-wide text-text-secondary">
                            Status
                        </th>

                    </tr>

                </thead>


                <tbody>

                    <tr>

                        <td colspan="4" class="px-5 py-10 text-center">

                            <p class="text-sm text-text-secondary">
                                No upcoming visits for today.
                            </p>

                        </td>

                    </tr>

                </tbody>

            </table>

        </div>

    </section>

</div>


{{-- =============================================================
    LIVE DATE + TIME
============================================================= --}}
<script>

function updateFrontDeskDateTime() {

    const now = new Date();

    const dateElement = document.getElementById('current-date');
    const timeElement = document.getElementById('current-time');

    if (!dateElement || !timeElement) {
        return;
    }

    dateElement.textContent = now.toLocaleDateString('en-US', {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric'
    });

    timeElement.textContent = now.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit'
    });

}

updateFrontDeskDateTime();

setInterval(updateFrontDeskDateTime, 1000);

</script>

@endsection