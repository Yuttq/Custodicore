@extends('layouts.frontdesk')

@section('title', "Today's Schedule")

@section('content')

<div class="w-full max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8 py-6">

    {{-- =========================================================
        PAGE HEADER
    ========================================================== --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between mb-6">

        <div>
            <h1 class="text-2xl sm:text-3xl font-bold tracking-tight text-primary-navy">
                Today's Schedule
            </h1>

            <p class="mt-1 text-sm text-text-secondary">
                View and manage approved visitor schedules.
            </p>
        </div>


        {{-- DATE + LIVE TIME --}}
        <div class="flex items-center gap-5 sm:gap-6 text-right">

            <div>
                <p class="text-[9px] font-semibold uppercase tracking-[0.18em] text-primary-navy">
                    Today
                </p>

                <p
                    id="current-date"
                    class="mt-1 text-xs sm:text-sm font-medium text-text-secondary whitespace-nowrap"
                >
                    {{ date('l, F d, Y') }}
                </p>
            </div>


            <div class="h-10 w-px bg-border"></div>


            <div>
                <p class="text-[9px] font-semibold uppercase tracking-[0.18em] text-primary-navy">
                    Current Time
                </p>

                <p
                    id="current-time"
                    class="mt-1 text-base sm:text-lg font-bold text-primary-navy whitespace-nowrap"
                >
                    {{ date('h:i:s A') }}
                </p>
            </div>

        </div>

    </div>


    {{-- =========================================================
        SUMMARY CARDS
    ========================================================== --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-6">

        {{-- Approved Visits --}}
        <div class="rounded-xl border border-border bg-white px-5 py-4 shadow-sm">

            <p class="text-xs font-semibold text-text-secondary">
                Approved Visits
            </p>

            <p class="mt-1 text-2xl font-bold leading-none text-primary-navy">
                12
            </p>

            <p class="mt-2 text-[11px] text-text-secondary">
                Scheduled visits
            </p>

        </div>


        {{-- Upcoming --}}
        <div class="rounded-xl border border-border bg-white px-5 py-4 shadow-sm">

            <p class="text-xs font-semibold text-text-secondary">
                Upcoming
            </p>

            <p class="mt-1 text-2xl font-bold leading-none text-primary-navy">
                7
            </p>

            <p class="mt-2 text-[11px] text-text-secondary">
                Visitors not yet checked in
            </p>

        </div>


        {{-- Checked In --}}
        <div class="rounded-xl border border-border bg-white px-5 py-4 shadow-sm">

            <p class="text-xs font-semibold text-text-secondary">
                Checked In
            </p>

            <p class="mt-1 text-2xl font-bold leading-none text-primary-navy">
                3
            </p>

            <p class="mt-2 text-[11px] text-text-secondary">
                Currently inside
            </p>

        </div>


        {{-- Completed --}}
        <div class="rounded-xl border border-border bg-white px-5 py-4 shadow-sm">

            <p class="text-xs font-semibold text-text-secondary">
                Completed
            </p>

            <p class="mt-1 text-2xl font-bold leading-none text-primary-navy">
                2
            </p>

            <p class="mt-2 text-[11px] text-text-secondary">
                Visits completed
            </p>

        </div>

    </div>


    {{-- =========================================================
        SCHEDULE CARD
    ========================================================== --}}
    <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">


        {{-- =====================================================
            TABLE HEADER
        ====================================================== --}}
        <div class="border-b border-border px-5 py-4 sm:px-6">

            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">

                <div>

                    <h2 class="text-base font-semibold text-primary-navy">
                        Visitor Schedule
                    </h2>

                    <p class="mt-0.5 text-xs text-text-secondary">
                        Approved visitor schedules based on the selected period.
                    </p>

                </div>


                {{-- =================================================
                    PERIOD DROPDOWN
                ================================================== --}}
                <div class="flex items-center gap-2">

                    <label
                        for="period-filter"
                        class="text-[10px] font-semibold uppercase tracking-wide text-text-secondary"
                    >
                        Filter by Period
                    </label>


                    <div class="relative">

                        <select
                            id="period-filter"
                            class="h-9 min-w-[150px] appearance-none rounded-lg border border-border bg-white pl-3 pr-9 text-[11px] font-semibold text-text-primary outline-none transition focus:border-primary-navy focus:ring-1 focus:ring-primary-navy"
                        >

                            <option value="today" selected>
                                Today
                            </option>

                            <option value="yesterday">
                                Yesterday
                            </option>

                            <option value="week">
                                This Week
                            </option>

                            <option value="month">
                                This Month
                            </option>

                            <option value="year">
                                This Year
                            </option>

                        </select>


                        {{-- Dropdown Arrow --}}
                        <div class="pointer-events-none absolute inset-y-0 right-3 flex items-center">

                            <svg
                                class="h-3.5 w-3.5 text-text-secondary"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="m6 9 6 6 6-6"
                                />
                            </svg>

                        </div>

                    </div>

                </div>

            </div>

        </div>


        {{-- =====================================================
            ACTIVE PERIOD
        ====================================================== --}}
        <div class="flex items-center justify-between border-b border-border bg-slate-50 px-5 py-3 sm:px-6">

            <div class="flex items-center gap-2">

                <span class="h-2 w-2 rounded-full bg-primary-navy"></span>

                <p
                    id="active-period"
                    class="text-[11px] font-semibold text-primary-navy"
                >
                    Today's approved visits
                </p>

            </div>


            <p class="text-[10px] text-text-secondary">
                12 visits
            </p>

        </div>


        {{-- =====================================================
            SCHEDULE TABLE
        ====================================================== --}}
        <div class="w-full overflow-x-auto">

            <table class="w-full min-w-[900px] table-fixed text-left">

                <thead>

                    <tr class="border-b border-border bg-white">

                        <th class="w-[21%] px-5 py-3 text-[10px] font-semibold uppercase tracking-wide text-text-secondary">
                            Visitor
                        </th>

                        <th class="w-[21%] px-5 py-3 text-[10px] font-semibold uppercase tracking-wide text-text-secondary">
                            PDL
                        </th>

                        <th class="w-[19%] px-5 py-3 text-[10px] font-semibold uppercase tracking-wide text-text-secondary">
                            Schedule
                        </th>

                        <th class="w-[15%] px-5 py-3 text-[10px] font-semibold uppercase tracking-wide text-text-secondary">
                            Visit Type
                        </th>

                        <th class="w-[12%] px-5 py-3 text-[10px] font-semibold uppercase tracking-wide text-text-secondary">
                            Status
                        </th>

                        <th class="w-[12%] px-5 py-3 text-[10px] font-semibold uppercase tracking-wide text-text-secondary">
                            Action
                        </th>

                    </tr>

                </thead>


                <tbody class="divide-y divide-border">


                    {{-- =================================================
                        VISIT 1
                    ================================================== --}}
                    <tr class="transition hover:bg-background">

                        <td class="px-5 py-4">

                            <p class="text-sm font-semibold text-text-primary">
                                Maria Santos
                            </p>

                            <p class="mt-0.5 text-[10px] text-text-secondary">
                                VIS-00124
                            </p>

                        </td>


                        <td class="px-5 py-4">

                            <p class="text-sm text-text-primary">
                                Juan Dela Cruz
                            </p>

                            <p class="mt-0.5 text-[10px] text-text-secondary">
                                PDL-00045
                            </p>

                        </td>


                        <td class="px-5 py-4">

                            <p class="text-sm font-medium text-text-primary">
                                9:00 AM – 10:00 AM
                            </p>

                        </td>


                        <td class="px-5 py-4">

                            <span class="text-xs text-text-secondary">
                                Regular Visit
                            </span>

                        </td>


                        <td class="px-5 py-4">

                            <span class="inline-flex rounded-full bg-success/10 px-2.5 py-1 text-[10px] font-semibold text-success">
                                Approved
                            </span>

                        </td>


                        <td class="px-5 py-4">

                            <button
                                type="button"
                                class="rounded-lg border border-border bg-white px-3 py-1.5 text-[10px] font-semibold text-primary-navy transition hover:bg-background"
                            >
                                View
                            </button>

                        </td>

                    </tr>


                    {{-- =================================================
                        VISIT 2
                    ================================================== --}}
                    <tr class="transition hover:bg-background">

                        <td class="px-5 py-4">

                            <p class="text-sm font-semibold text-text-primary">
                                Ana Reyes
                            </p>

                            <p class="mt-0.5 text-[10px] text-text-secondary">
                                VIS-00125
                            </p>

                        </td>


                        <td class="px-5 py-4">

                            <p class="text-sm text-text-primary">
                                Pedro Garcia
                            </p>

                            <p class="mt-0.5 text-[10px] text-text-secondary">
                                PDL-00031
                            </p>

                        </td>


                        <td class="px-5 py-4">

                            <p class="text-sm font-medium text-text-primary">
                                10:30 AM – 11:30 AM
                            </p>

                        </td>


                        <td class="px-5 py-4">

                            <span class="text-xs text-text-secondary">
                                Regular Visit
                            </span>

                        </td>


                        <td class="px-5 py-4">

                            <span class="inline-flex rounded-full bg-warning/10 px-2.5 py-1 text-[10px] font-semibold text-warning">
                                Upcoming
                            </span>

                        </td>


                        <td class="px-5 py-4">

                            <button
                                type="button"
                                class="rounded-lg border border-border bg-white px-3 py-1.5 text-[10px] font-semibold text-primary-navy transition hover:bg-background"
                            >
                                View
                            </button>

                        </td>

                    </tr>


                    {{-- =================================================
                        VISIT 3
                    ================================================== --}}
                    <tr class="transition hover:bg-background">

                        <td class="px-5 py-4">

                            <p class="text-sm font-semibold text-text-primary">
                                Carlos Mendoza
                            </p>

                            <p class="mt-0.5 text-[10px] text-text-secondary">
                                VIS-00126
                            </p>

                        </td>


                        <td class="px-5 py-4">

                            <p class="text-sm text-text-primary">
                                Roberto Aquino
                            </p>

                            <p class="mt-0.5 text-[10px] text-text-secondary">
                                PDL-00018
                            </p>

                        </td>


                        <td class="px-5 py-4">

                            <p class="text-sm font-medium text-text-primary">
                                1:00 PM – 2:00 PM
                            </p>

                        </td>


                        <td class="px-5 py-4">

                            <span class="text-xs text-text-secondary">
                                Regular Visit
                            </span>

                        </td>


                        <td class="px-5 py-4">

                            <span class="inline-flex rounded-full bg-info/10 px-2.5 py-1 text-[10px] font-semibold text-info">
                                Scheduled
                            </span>

                        </td>


                        <td class="px-5 py-4">

                            <button
                                type="button"
                                class="rounded-lg border border-border bg-white px-3 py-1.5 text-[10px] font-semibold text-primary-navy transition hover:bg-background"
                            >
                                View
                            </button>

                        </td>

                    </tr>


                    {{-- =================================================
                        VISIT 4
                    ================================================== --}}
                    <tr class="transition hover:bg-background">

                        <td class="px-5 py-4">

                            <p class="text-sm font-semibold text-text-primary">
                                Elena Garcia
                            </p>

                            <p class="mt-0.5 text-[10px] text-text-secondary">
                                VIS-00127
                            </p>

                        </td>


                        <td class="px-5 py-4">

                            <p class="text-sm text-text-primary">
                                Michael Torres
                            </p>

                            <p class="mt-0.5 text-[10px] text-text-secondary">
                                PDL-00052
                            </p>

                        </td>


                        <td class="px-5 py-4">

                            <p class="text-sm font-medium text-text-primary">
                                2:30 PM – 3:30 PM
                            </p>

                        </td>


                        <td class="px-5 py-4">

                            <span class="text-xs text-text-secondary">
                                Special Visit
                            </span>

                        </td>


                        <td class="px-5 py-4">

                            <span class="inline-flex rounded-full bg-info/10 px-2.5 py-1 text-[10px] font-semibold text-info">
                                Scheduled
                            </span>

                        </td>


                        <td class="px-5 py-4">

                            <button
                                type="button"
                                class="rounded-lg border border-border bg-white px-3 py-1.5 text-[10px] font-semibold text-primary-navy transition hover:bg-background"
                            >
                                View
                            </button>

                        </td>

                    </tr>


                    {{-- =================================================
                        VISIT 5
                    ================================================== --}}
                    <tr class="transition hover:bg-background">

                        <td class="px-5 py-4">

                            <p class="text-sm font-semibold text-text-primary">
                                Sofia Ramos
                            </p>

                            <p class="mt-0.5 text-[10px] text-text-secondary">
                                VIS-00128
                            </p>

                        </td>


                        <td class="px-5 py-4">

                            <p class="text-sm text-text-primary">
                                Daniel Flores
                            </p>

                            <p class="mt-0.5 text-[10px] text-text-secondary">
                                PDL-00063
                            </p>

                        </td>


                        <td class="px-5 py-4">

                            <p class="text-sm font-medium text-text-primary">
                                4:00 PM – 5:00 PM
                            </p>

                        </td>


                        <td class="px-5 py-4">

                            <span class="text-xs text-text-secondary">
                                Regular Visit
                            </span>

                        </td>


                        <td class="px-5 py-4">

                            <span class="inline-flex rounded-full bg-info/10 px-2.5 py-1 text-[10px] font-semibold text-info">
                                Scheduled
                            </span>

                        </td>


                        <td class="px-5 py-4">

                            <button
                                type="button"
                                class="rounded-lg border border-border bg-white px-3 py-1.5 text-[10px] font-semibold text-primary-navy transition hover:bg-background"
                            >
                                View
                            </button>

                        </td>

                    </tr>

                </tbody>

            </table>

        </div>


        {{-- =========================================================
            PAGINATION FOOTER
        ========================================================== --}}
        <div class="flex flex-col gap-3 border-t border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">

            <p class="text-[11px] text-text-secondary">

                Showing
                <span class="font-semibold text-text-primary">
                    1–5
                </span>

                of

                <span class="font-semibold text-text-primary">
                    12
                </span>

                visits

            </p>


            <div class="flex items-center gap-2">

                <button
                    type="button"
                    class="flex items-center gap-1 rounded-lg border border-border bg-white px-3 py-2 text-[10px] font-semibold text-text-secondary transition hover:bg-background"
                >
                    <span>←</span>
                    Previous
                </button>


                <button
                    type="button"
                    class="flex items-center gap-1 rounded-lg border border-border bg-white px-3 py-2 text-[10px] font-semibold text-primary-navy transition hover:bg-background"
                >
                    Next
                    <span>→</span>
                </button>

            </div>

        </div>

    </section>

</div>


{{-- =============================================================
    LIVE DATE + TIME
============================================================= --}}
<script>

    function updateFrontDeskDateTime() {

        const now = new Date();

        const dateElement =
            document.getElementById('current-date');

        const timeElement =
            document.getElementById('current-time');


        if (!dateElement || !timeElement) {
            return;
        }


        dateElement.textContent =
            now.toLocaleDateString('en-US', {
                weekday: 'long',
                month: 'long',
                day: 'numeric',
                year: 'numeric'
            });


        timeElement.textContent =
            now.toLocaleTimeString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
                second: '2-digit'
            });

    }


    updateFrontDeskDateTime();

    setInterval(updateFrontDeskDateTime, 1000);

</script>


{{-- =============================================================
    PERIOD DROPDOWN
============================================================= --}}
<script>

    document.addEventListener('DOMContentLoaded', function () {

        const periodFilter =
            document.getElementById('period-filter');

        const activePeriod =
            document.getElementById('active-period');


        const periodLabels = {

            today:
                "Today's approved visits",

            yesterday:
                "Yesterday's approved visits",

            week:
                "This week's approved visits",

            month:
                "This month's approved visits",

            year:
                "This year's approved visits"

        };


        if (periodFilter) {

            periodFilter.addEventListener('change', function () {

                const selectedPeriod =
                    this.value;


                if (
                    activePeriod &&
                    periodLabels[selectedPeriod]
                ) {

                    activePeriod.textContent =
                        periodLabels[selectedPeriod];

                }

            });

        }

    });

</script>

@endsection