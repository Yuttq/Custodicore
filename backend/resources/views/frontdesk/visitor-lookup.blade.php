@extends('layouts.frontdesk')

@section('title', 'Visitor Lookup')

@section('content')

<div class="w-full max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8 py-6">

    {{-- =========================================================
        PAGE HEADER
    ========================================================== --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between mb-6">

        <div>

            <h1 class="text-2xl sm:text-3xl font-bold tracking-tight text-primary-navy">
                Visitor Lookup
            </h1>

            <p class="mt-1 text-sm text-text-secondary">
                Find and review visitor information, visit history, and eligibility status.
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
        SEARCH VISITOR
    ========================================================== --}}
    <section class="mb-6 rounded-xl border border-border bg-white shadow-sm">

        <div class="border-b border-border px-5 py-4 sm:px-6">

            <div class="flex items-start gap-3">

                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-navy text-sm font-bold text-white">
                    1
                </div>

                <div>

                    <h2 class="text-base font-semibold text-primary-navy">
                        Search Visitor
                    </h2>

                    <p class="mt-0.5 text-xs text-text-secondary">
                        Search by visitor name, visitor ID, or scan a QR code.
                    </p>

                </div>

            </div>

        </div>


        <div class="p-5 sm:p-6">

            <div class="flex flex-col gap-3 lg:flex-row">

                {{-- Search Input --}}
                <div class="relative flex-1">

                    <div class="pointer-events-none absolute inset-y-0 left-3 flex items-center">

                        <svg
                            class="h-4 w-4 text-text-secondary"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z"
                            />
                        </svg>

                    </div>


                    <input
                        type="text"
                        id="visitor-search"
                        value="Maria Santos"
                        placeholder="Enter visitor name, visitor ID, or scan QR code..."
                        class="h-11 w-full rounded-lg border border-border bg-white pl-10 pr-4 text-sm text-text-primary outline-none transition placeholder:text-text-secondary focus:border-primary-navy focus:ring-1 focus:ring-primary-navy"
                    />

                </div>


                {{-- QR Button --}}
                <button
                    type="button"
                    id="scan-qr-button"
                    class="flex h-11 items-center justify-center gap-2 rounded-lg border border-border bg-white px-5 text-xs font-semibold text-primary-navy transition hover:bg-background"
                >

                    <svg
                        class="h-4 w-4"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <rect x="3" y="3" width="7" height="7" rx="1"/>
                        <rect x="14" y="3" width="7" height="7" rx="1"/>
                        <rect x="3" y="14" width="7" height="7" rx="1"/>
                        <path d="M14 14h3v3h-3zM18 18h3v3h-3zM14 18h2"/>
                    </svg>

                    Scan QR

                </button>


                {{-- Search --}}
                <button
                    type="button"
                    id="search-button"
                    class="flex h-11 items-center justify-center gap-2 rounded-lg bg-primary-navy px-6 text-xs font-semibold text-white transition hover:bg-blue-900"
                >

                    <svg
                        class="h-4 w-4"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        stroke-width="2"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z"
                        />
                    </svg>

                    Search

                </button>

            </div>

        </div>

    </section>


    {{-- =========================================================
        MAIN VISITOR LOOKUP AREA
    ========================================================== --}}
    <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.35fr)] gap-4 mb-6">


        {{-- =====================================================
            LEFT COLUMN
        ====================================================== --}}
        <div class="min-w-0 flex flex-col gap-4">


            {{-- =================================================
                VISITOR INFORMATION
            ================================================== --}}
            <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">

                <div class="flex items-center justify-between border-b border-border px-5 py-4">

                    <div>

                        <h2 class="text-base font-semibold text-primary-navy">
                            Visitor Information
                        </h2>

                        <p class="mt-0.5 text-xs text-text-secondary">
                            Registered visitor profile information.
                        </p>

                    </div>


                    <button
                        type="button"
                        class="rounded-lg border border-border bg-white px-3 py-1.5 text-[10px] font-semibold text-primary-navy transition hover:bg-background"
                    >
                        Edit
                    </button>

                </div>


                <div class="p-5">

                    {{-- Profile --}}
                    <div class="flex items-center gap-4 mb-5">

                        <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-full bg-blue-100 text-2xl font-bold text-primary-navy">
                            MS
                        </div>


                        <div class="min-w-0">

                            <h3 class="text-lg font-bold text-primary-navy">
                                Maria Santos
                            </h3>

                            <p class="mt-0.5 text-xs text-text-secondary">
                                VIS-2026-00124
                            </p>


                            <div class="mt-2 flex flex-wrap gap-2">

                                <span class="inline-flex rounded-full bg-success/10 px-2.5 py-1 text-[10px] font-semibold text-success">
                                    ✓ Verified
                                </span>

                                <span class="inline-flex rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-semibold text-emerald-600">
                                    ✓ Eligible
                                </span>

                            </div>

                        </div>

                    </div>


                    {{-- Details --}}
                    <div class="grid grid-cols-1 gap-y-3 sm:grid-cols-2 sm:gap-x-5">

                        <div>

                            <p class="text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                Date of Birth
                            </p>

                            <p class="mt-0.5 text-xs font-medium text-text-primary">
                                April 12, 1990
                            </p>

                        </div>


                        <div>

                            <p class="text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                Contact No.
                            </p>

                            <p class="mt-0.5 text-xs font-medium text-text-primary">
                                0917 123 4567
                            </p>

                        </div>


                        <div>

                            <p class="text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                Address
                            </p>

                            <p class="mt-0.5 text-xs font-medium text-text-primary">
                                123 Rizal St., Manila
                            </p>

                        </div>


                        <div>

                            <p class="text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                Relationship to PDL
                            </p>

                            <p class="mt-0.5 text-xs font-medium text-text-primary">
                                Spouse
                            </p>

                        </div>


                        <div>

                            <p class="text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                Registered Date
                            </p>

                            <p class="mt-0.5 text-xs font-medium text-text-primary">
                                May 1, 2026
                            </p>

                        </div>

                    </div>


                    {{-- Associated PDL --}}
                    <div class="mt-5 rounded-lg border border-blue-100 bg-blue-50/50 p-4">

                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

                            <div>

                                <p class="text-[9px] font-semibold uppercase tracking-wide text-primary-navy">
                                    Associated PDL
                                </p>

                                <p class="mt-1 text-sm font-semibold text-text-primary">
                                    Juan Dela Cruz
                                </p>

                                <p class="text-[10px] text-text-secondary">
                                    PDL ID: PDL-00045
                                </p>

                            </div>


                            <button
                                type="button"
                                class="rounded-lg border border-primary-navy bg-white px-3 py-1.5 text-[10px] font-semibold text-primary-navy transition hover:bg-primary-navy hover:text-white"
                            >
                                View PDL Record
                            </button>

                        </div>

                    </div>


                    {{-- Visitor Statistics --}}
                    <div class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">

                        <div class="rounded-lg bg-slate-50 p-3">

                            <p class="text-[9px] text-text-secondary">
                                Total Visits
                            </p>

                            <p class="mt-1 text-lg font-bold text-primary-navy">
                                5
                            </p>

                        </div>


                        <div class="rounded-lg bg-slate-50 p-3">

                            <p class="text-[9px] text-text-secondary">
                                Completed
                            </p>

                            <p class="mt-1 text-lg font-bold text-primary-navy">
                                4
                            </p>

                        </div>


                        <div class="rounded-lg bg-rose-50 p-3">

                            <p class="text-[9px] text-rose-500">
                                No-Shows
                            </p>

                            <p class="mt-1 text-lg font-bold text-rose-500">
                                1
                            </p>

                        </div>


                        <div class="rounded-lg bg-blue-50 p-3">

                            <p class="text-[9px] text-text-secondary">
                                Last Visit
                            </p>

                            <p class="mt-1 text-xs font-bold text-primary-navy">
                                May 10
                            </p>

                        </div>

                    </div>

                </div>

            </section>


            {{-- =================================================
                ELIGIBILITY SUMMARY
            ================================================== --}}
            <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">

                <div class="flex items-center justify-between border-b border-border px-5 py-4">

                    <div>

                        <h2 class="text-base font-semibold text-primary-navy">
                            Eligibility Summary
                        </h2>

                        <p class="mt-0.5 text-xs text-text-secondary">
                            Current visitor eligibility assessment.
                        </p>

                    </div>


                    <button
                        type="button"
                        data-modal-open="eligibility-modal"
                        class="rounded-lg border border-primary-navy bg-white px-3 py-1.5 text-[10px] font-semibold text-primary-navy transition hover:bg-primary-navy hover:text-white"
                    >
                        View Assessment
                    </button>

                </div>


                <div class="p-5">

                    <div class="rounded-lg border border-emerald-100 bg-emerald-50 p-4">

                        <div class="flex items-start gap-3">

                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white">
                                ✓
                            </div>


                            <div>

                                <p class="text-sm font-bold text-emerald-700">
                                    Eligible for Visit
                                </p>

                                <p class="mt-0.5 text-[10px] text-emerald-700/80">
                                    This visitor currently meets the requirements for visitation.
                                </p>

                                <p class="mt-1 text-[9px] text-text-secondary">
                                    Last assessment: May 10, 2026
                                </p>

                            </div>

                        </div>

                    </div>


                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">

                        @php
                            $checks = [
                                ['label' => 'Valid Identification', 'value' => 'Verified'],
                                ['label' => 'Relationship Verification', 'value' => 'Verified'],
                                ['label' => 'Schedule Compliance', 'value' => 'Compliant'],
                                ['label' => 'PDL Eligibility', 'value' => 'Eligible'],
                                ['label' => 'Watchlist Check', 'value' => 'No Match'],
                                ['label' => 'Visit Frequency', 'value' => 'Within Limit'],
                            ];
                        @endphp

                        @foreach($checks as $check)

                            <div class="flex items-center justify-between gap-3">

                                <div class="flex min-w-0 items-center gap-2">

                                    <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-[9px] text-white">
                                        ✓
                                    </span>

                                    <span class="truncate text-[10px] font-medium text-text-primary">
                                        {{ $check['label'] }}
                                    </span>

                                </div>

                                <span class="shrink-0 text-[9px] font-semibold text-emerald-600">
                                    {{ $check['value'] }}
                                </span>

                            </div>

                        @endforeach

                    </div>

                </div>

            </section>

        </div>


        {{-- =====================================================
            RIGHT COLUMN
        ====================================================== --}}
        <div class="min-w-0 flex flex-col gap-4">


            {{-- =================================================
                VISIT HISTORY
            ================================================== --}}
            <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">

                <div class="flex flex-col gap-3 border-b border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between">

                    <div>

                        <h2 class="text-base font-semibold text-primary-navy">
                            Visit History
                        </h2>

                        <p class="mt-0.5 text-xs text-text-secondary">
                            Previous visitation records.
                        </p>

                    </div>


                    <div class="relative">

                        <svg
                            class="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-text-secondary"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z"
                            />
                        </svg>

                        <input
                            type="text"
                            placeholder="Search visit history..."
                            class="h-9 w-full rounded-lg border border-border bg-white pl-9 pr-3 text-[10px] outline-none focus:border-primary-navy focus:ring-1 focus:ring-primary-navy sm:w-48"
                        />

                    </div>

                </div>


                <div class="w-full overflow-x-auto">

                    <table class="w-full min-w-[780px] table-fixed text-left">

                        <thead>

                            <tr class="border-b border-border bg-slate-50">

                                <th class="w-[13%] px-4 py-3 text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                    Date
                                </th>

                                <th class="w-[14%] px-4 py-3 text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                    Scheduled Time
                                </th>

                                <th class="w-[20%] px-4 py-3 text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                    PDL Name
                                </th>

                                <th class="w-[15%] px-4 py-3 text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                    Status
                                </th>

                                <th class="w-[14%] px-4 py-3 text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                    Check-In
                                </th>

                                <th class="w-[14%] px-4 py-3 text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                    Check-Out
                                </th>

                                <th class="w-[10%] px-4 py-3 text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                    Action
                                </th>

                            </tr>

                        </thead>


                        <tbody class="divide-y divide-border">

                            @php
                                $visits = [
                                    [
                                        'date' => 'May 15, 2026',
                                        'schedule' => '9:00 AM',
                                        'pdl' => 'Juan Dela Cruz',
                                        'status' => 'Completed',
                                        'checkin' => '8:56 AM',
                                        'checkout' => '10:14 AM',
                                        'color' => 'success'
                                    ],
                                    [
                                        'date' => 'May 10, 2026',
                                        'schedule' => '1:00 PM',
                                        'pdl' => 'Juan Dela Cruz',
                                        'status' => 'Completed',
                                        'checkin' => '1:03 PM',
                                        'checkout' => '4:12 PM',
                                        'color' => 'success'
                                    ],
                                    [
                                        'date' => 'May 3, 2026',
                                        'schedule' => '9:00 AM',
                                        'pdl' => 'Juan Dela Cruz',
                                        'status' => 'No-Show',
                                        'checkin' => '-',
                                        'checkout' => '-',
                                        'color' => 'danger'
                                    ],
                                    [
                                        'date' => 'April 20, 2026',
                                        'schedule' => '2:30 PM',
                                        'pdl' => 'Juan Dela Cruz',
                                        'status' => 'Completed',
                                        'checkin' => '2:28 PM',
                                        'checkout' => '4:05 PM',
                                        'color' => 'success'
                                    ],
                                    [
                                        'date' => 'April 6, 2026',
                                        'schedule' => '9:00 AM',
                                        'pdl' => 'Juan Dela Cruz',
                                        'status' => 'Completed',
                                        'checkin' => '8:51 AM',
                                        'checkout' => '11:30 AM',
                                        'color' => 'success'
                                    ],
                                ];
                            @endphp


                            @foreach($visits as $visit)

                                <tr class="transition hover:bg-background">

                                    <td class="px-4 py-3 text-[10px] font-medium text-text-primary">
                                        {{ $visit['date'] }}
                                    </td>

                                    <td class="px-4 py-3 text-[10px] text-text-primary">
                                        {{ $visit['schedule'] }}
                                    </td>

                                    <td class="px-4 py-3 text-[10px] text-text-primary">
                                        {{ $visit['pdl'] }}
                                    </td>

                                    <td class="px-4 py-3">

                                        @if($visit['color'] === 'success')

                                            <span class="inline-flex rounded-full bg-success/10 px-2 py-1 text-[9px] font-semibold text-success">
                                                {{ $visit['status'] }}
                                            </span>

                                        @else

                                            <span class="inline-flex rounded-full bg-rose-50 px-2 py-1 text-[9px] font-semibold text-rose-500">
                                                {{ $visit['status'] }}
                                            </span>

                                        @endif

                                    </td>

                                    <td class="px-4 py-3 text-[10px] text-text-primary">
                                        {{ $visit['checkin'] }}
                                    </td>

                                    <td class="px-4 py-3 text-[10px] text-text-primary">
                                        {{ $visit['checkout'] }}
                                    </td>

                                    <td class="px-4 py-3">

                                        <button
                                            type="button"
                                            data-modal-open="visit-details-modal"
                                            class="rounded-lg border border-border px-2.5 py-1.5 text-[9px] font-semibold text-primary-navy transition hover:bg-background"
                                        >
                                            Details
                                        </button>

                                    </td>

                                </tr>

                            @endforeach

                        </tbody>

                    </table>

                </div>


                {{-- Pagination --}}
                <div class="flex flex-col gap-3 border-t border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between">

                    <p class="text-[10px] text-text-secondary">
                        Showing
                        <span class="font-semibold text-text-primary">1–5</span>
                        of
                        <span class="font-semibold text-text-primary">5</span>
                        visits
                    </p>


                    <div class="flex items-center gap-1">

                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-border text-xs text-text-secondary hover:bg-background"
                        >
                            ←
                        </button>

                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-navy text-xs font-semibold text-white"
                        >
                            1
                        </button>

                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-lg border border-border text-xs text-text-secondary hover:bg-background"
                        >
                            →
                        </button>

                    </div>

                </div>

            </section>


            {{-- =================================================
                QUICK ACTIONS
            ================================================== --}}
            <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">

                <div class="border-b border-border px-5 py-4">

                    <h2 class="text-base font-semibold text-primary-navy">
                        Quick Actions
                    </h2>

                    <p class="mt-0.5 text-xs text-text-secondary">
                        Common visitor-related actions.
                    </p>

                </div>


                <div class="grid grid-cols-1 gap-3 p-4 md:grid-cols-3">

                    {{-- Full Profile --}}
                    <button
                        type="button"
                        data-modal-open="profile-modal"
                        class="group flex items-center gap-3 rounded-lg border border-border bg-white p-4 text-left transition hover:border-blue-200 hover:bg-blue-50/40"
                    >

                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-100 text-primary-navy">

                            <svg
                                class="h-5 w-5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="1.8"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M15.75 6.75a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.25a7.5 7.5 0 0 1 15 0"
                                />
                            </svg>

                        </div>


                        <div class="min-w-0 flex-1">

                            <p class="text-xs font-semibold text-primary-navy">
                                View Full Profile
                            </p>

                            <p class="mt-0.5 text-[9px] text-text-secondary">
                                See complete visitor details
                            </p>

                        </div>

                        <span class="text-text-secondary">
                            →
                        </span>

                    </button>


                    {{-- Eligibility --}}
                    <button
                        type="button"
                        data-modal-open="eligibility-modal"
                        class="group flex items-center gap-3 rounded-lg border border-border bg-white p-4 text-left transition hover:border-blue-200 hover:bg-blue-50/40"
                    >

                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-100 text-primary-navy">

                            <svg
                                class="h-5 w-5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="1.8"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M9 12.75 11.25 15 15 9.75M12 3l7.5 3.75v5.625c0 4.5-3.375 7.875-7.5 8.625-4.125-.75-7.5-4.125-7.5-8.625V6.75L12 3Z"
                                />
                            </svg>

                        </div>


                        <div class="min-w-0 flex-1">

                            <p class="text-xs font-semibold text-primary-navy">
                                View Eligibility Record
                            </p>

                            <p class="mt-0.5 text-[9px] text-text-secondary">
                                See latest eligibility assessment
                            </p>

                        </div>

                        <span class="text-text-secondary">
                            →
                        </span>

                    </button>


                    {{-- PDL --}}
                    <button
                        type="button"
                        class="group flex items-center gap-3 rounded-lg border border-border bg-white p-4 text-left transition hover:border-blue-200 hover:bg-blue-50/40"
                    >

                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-100 text-primary-navy">

                            <svg
                                class="h-5 w-5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="1.8"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M16.5 18.75h-9a3 3 0 0 1-3-3V8.25a3 3 0 0 1 3-3h9a3 3 0 0 1 3 3v7.5a3 3 0 0 1-3 3ZM9 10.5h6M9 14.25h3"
                                />
                            </svg>

                        </div>


                        <div class="min-w-0 flex-1">

                            <p class="text-xs font-semibold text-primary-navy">
                                View PDL Record
                            </p>

                            <p class="mt-0.5 text-[9px] text-text-secondary">
                                Go to associated PDL profile
                            </p>

                        </div>

                        <span class="text-text-secondary">
                            →
                        </span>

                    </button>

                </div>

            </section>

        </div>

    </div>


    {{-- =========================================================
        VISIT DETAILS MODAL
    ========================================================== --}}
    <div
        id="visit-details-modal"
        class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/50 px-4 py-6"
    >

        <div class="w-full max-w-xl rounded-xl border border-border bg-white shadow-2xl">

            <div class="flex items-center justify-between border-b border-border px-5 py-4">

                <div>

                    <h2 class="text-base font-semibold text-primary-navy">
                        Visit Details
                    </h2>

                    <p class="mt-0.5 text-[10px] text-text-secondary">
                        Complete details of the selected visit record.
                    </p>

                </div>


                <button
                    type="button"
                    data-modal-close="visit-details-modal"
                    class="text-lg text-text-secondary hover:text-primary-navy"
                >
                    ×
                </button>

            </div>


            <div class="grid grid-cols-1 gap-4 p-5 sm:grid-cols-2">

                @php
                    $visitDetails = [
                        'Visitor Name' => 'Maria Santos',
                        'Visitor ID' => 'VIS-2026-00124',
                        'PDL Name' => 'Juan Dela Cruz',
                        'PDL ID' => 'PDL-00045',
                        'Scheduled Date' => 'May 15, 2026',
                        'Scheduled Time' => '9:00 AM – 10:00 AM',
                        'Check-In Time' => '8:56 AM',
                        'Check-Out Time' => '10:14 AM',
                        'Visit Type' => 'Regular Visit',
                        'Status' => 'Completed',
                        'ID Surrendered' => 'Yes',
                        'ID Returned' => 'Yes',
                        'Front Desk Officer' => 'Officer Reyes',
                        'Remarks' => '-',
                    ];
                @endphp


                @foreach($visitDetails as $label => $value)

                    <div>

                        <p class="text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                            {{ $label }}
                        </p>

                        <p class="mt-1 text-xs font-medium text-text-primary">
                            {{ $value }}
                        </p>

                    </div>

                @endforeach

            </div>


            <div class="flex justify-end border-t border-border px-5 py-4">

                <button
                    type="button"
                    data-modal-close="visit-details-modal"
                    class="rounded-lg bg-primary-navy px-5 py-2 text-xs font-semibold text-white hover:bg-blue-900"
                >
                    Close
                </button>

            </div>

        </div>

    </div>


    {{-- =========================================================
        ELIGIBILITY MODAL
    ========================================================== --}}
    <div
        id="eligibility-modal"
        class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/50 px-4 py-6"
    >

        <div class="w-full max-w-xl rounded-xl border border-border bg-white shadow-2xl">

            <div class="flex items-center justify-between border-b border-border px-5 py-4">

                <div>

                    <h2 class="text-base font-semibold text-primary-navy">
                        Visitor Eligibility Assessment
                    </h2>

                    <p class="mt-0.5 text-[10px] text-text-secondary">
                        Detailed eligibility assessment for Maria Santos.
                    </p>

                </div>


                <button
                    type="button"
                    data-modal-close="eligibility-modal"
                    class="text-lg text-text-secondary hover:text-primary-navy"
                >
                    ×
                </button>

            </div>


            <div class="p-5">

                <div class="rounded-lg bg-emerald-50 p-4">

                    <div class="flex items-center gap-3">

                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white">
                            ✓
                        </div>

                        <div>

                            <p class="text-sm font-bold text-emerald-700">
                                Eligible for Visit
                            </p>

                            <p class="text-[10px] text-emerald-700/80">
                                This visitor meets the current visitation requirements.
                            </p>

                        </div>

                    </div>

                </div>


                <div class="mt-4 space-y-2">

                    @php
                        $assessment = [
                            ['label' => 'Valid Identification', 'description' => 'Government ID verified and active.', 'result' => 'Verified'],
                            ['label' => 'Relationship Verification', 'description' => 'Relationship to PDL confirmed.', 'result' => 'Verified'],
                            ['label' => 'Schedule Compliance', 'description' => 'Visitor aligned with approved schedule.', 'result' => 'Compliant'],
                            ['label' => 'PDL Eligibility', 'description' => 'Associated PDL is eligible for visitation.', 'result' => 'Eligible'],
                            ['label' => 'Watchlist Check', 'description' => 'No matching watchlist record found.', 'result' => 'No Match'],
                            ['label' => 'Visit Frequency', 'description' => 'Visitor is within allowed visit frequency.', 'result' => 'Within Limit'],
                        ];
                    @endphp


                    @foreach($assessment as $item)

                        <div class="flex items-center gap-3 rounded-lg border border-border px-3 py-3">

                            <div class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-xs font-bold text-emerald-600">
                                ✓
                            </div>


                            <div class="min-w-0 flex-1">

                                <p class="text-xs font-semibold text-text-primary">
                                    {{ $item['label'] }}
                                </p>

                                <p class="mt-0.5 text-[9px] text-text-secondary">
                                    {{ $item['description'] }}
                                </p>

                            </div>


                            <span class="shrink-0 rounded-full bg-emerald-50 px-2 py-1 text-[9px] font-semibold text-emerald-600">
                                {{ $item['result'] }}
                            </span>

                        </div>

                    @endforeach

                </div>

            </div>


            <div class="flex justify-end border-t border-border px-5 py-4">

                <button
                    type="button"
                    data-modal-close="eligibility-modal"
                    class="rounded-lg bg-primary-navy px-5 py-2 text-xs font-semibold text-white hover:bg-blue-900"
                >
                    Close
                </button>

            </div>

        </div>

    </div>


    {{-- =========================================================
        FULL PROFILE MODAL
    ========================================================== --}}
    <div
        id="profile-modal"
        class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/50 px-4 py-6"
    >

        <div class="w-full max-w-3xl rounded-xl border border-border bg-white shadow-2xl">

            <div class="flex items-center justify-between border-b border-border px-5 py-4">

                <div>

                    <h2 class="text-base font-semibold text-primary-navy">
                        Visitor Profile
                    </h2>

                    <p class="mt-0.5 text-[10px] text-text-secondary">
                        Complete visitor profile information.
                    </p>

                </div>


                <button
                    type="button"
                    data-modal-close="profile-modal"
                    class="text-lg text-text-secondary hover:text-primary-navy"
                >
                    ×
                </button>

            </div>


            <div class="grid grid-cols-1 gap-4 p-5 lg:grid-cols-[1.3fr_0.7fr]">

                {{-- Main Profile --}}
                <div class="rounded-lg border border-border p-4">

                    <div class="flex items-center gap-4 border-b border-border pb-4">

                        <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-blue-100 text-xl font-bold text-primary-navy">
                            MS
                        </div>


                        <div>

                            <h3 class="text-base font-bold text-primary-navy">
                                Maria Santos
                            </h3>

                            <p class="text-[10px] text-text-secondary">
                                VIS-2026-00124
                            </p>

                            <div class="mt-2 flex gap-2">

                                <span class="rounded-full bg-success/10 px-2 py-1 text-[9px] font-semibold text-success">
                                    ✓ Verified
                                </span>

                                <span class="rounded-full bg-emerald-50 px-2 py-1 text-[9px] font-semibold text-emerald-600">
                                    ✓ Eligible
                                </span>

                            </div>

                        </div>

                    </div>


                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">

                        <div>
                            <p class="text-[9px] uppercase tracking-wide text-text-secondary">
                                Date of Birth
                            </p>
                            <p class="mt-1 text-xs font-medium">
                                April 12, 1990
                            </p>
                        </div>

                        <div>
                            <p class="text-[9px] uppercase tracking-wide text-text-secondary">
                                Gender
                            </p>
                            <p class="mt-1 text-xs font-medium">
                                Female
                            </p>
                        </div>

                        <div>
                            <p class="text-[9px] uppercase tracking-wide text-text-secondary">
                                Contact No.
                            </p>
                            <p class="mt-1 text-xs font-medium">
                                0917 123 4567
                            </p>
                        </div>

                        <div>
                            <p class="text-[9px] uppercase tracking-wide text-text-secondary">
                                Email Address
                            </p>
                            <p class="mt-1 text-xs font-medium">
                                maria.santos@email.com
                            </p>
                        </div>

                        <div>
                            <p class="text-[9px] uppercase tracking-wide text-text-secondary">
                                Address
                            </p>
                            <p class="mt-1 text-xs font-medium">
                                123 Rizal St., Manila
                            </p>
                        </div>

                        <div>
                            <p class="text-[9px] uppercase tracking-wide text-text-secondary">
                                Relationship to PDL
                            </p>
                            <p class="mt-1 text-xs font-medium">
                                Spouse
                            </p>
                        </div>

                        <div>
                            <p class="text-[9px] uppercase tracking-wide text-text-secondary">
                                Registered Date
                            </p>
                            <p class="mt-1 text-xs font-medium">
                                May 1, 2026
                            </p>
                        </div>

                        <div>
                            <p class="text-[9px] uppercase tracking-wide text-text-secondary">
                                Status
                            </p>
                            <p class="mt-1 text-xs font-semibold text-success">
                                Active
                            </p>
                        </div>

                    </div>

                </div>


                {{-- Right Profile Details --}}
                <div class="space-y-4">

                    <div class="rounded-lg border border-border p-4">

                        <p class="text-xs font-semibold text-primary-navy">
                            Emergency Contact
                        </p>

                        <div class="mt-3 space-y-2">

                            <div>
                                <p class="text-[9px] text-text-secondary">
                                    Name
                                </p>
                                <p class="text-xs font-medium">
                                    Pedro Santos
                                </p>
                            </div>

                            <div>
                                <p class="text-[9px] text-text-secondary">
                                    Relationship
                                </p>
                                <p class="text-xs font-medium">
                                    Father
                                </p>
                            </div>

                            <div>
                                <p class="text-[9px] text-text-secondary">
                                    Contact No.
                                </p>
                                <p class="text-xs font-medium">
                                    0918 765 4321
                                </p>
                            </div>

                        </div>

                    </div>


                    <div class="rounded-lg border border-border p-4">

                        <p class="text-xs font-semibold text-primary-navy">
                            Additional Information
                        </p>

                        <div class="mt-3 space-y-2">

                            <div>
                                <p class="text-[9px] text-text-secondary">
                                    Occupation
                                </p>
                                <p class="text-xs font-medium">
                                    Private Employee
                                </p>
                            </div>

                            <div>
                                <p class="text-[9px] text-text-secondary">
                                    Valid ID Type
                                </p>
                                <p class="text-xs font-medium">
                                    Philippine National ID
                                </p>
                            </div>

                            <div>
                                <p class="text-[9px] text-text-secondary">
                                    ID Number
                                </p>
                                <p class="text-xs font-medium">
                                    1234 5678 9012
                                </p>
                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <div class="flex justify-end border-t border-border px-5 py-4">

                <button
                    type="button"
                    data-modal-close="profile-modal"
                    class="rounded-lg bg-primary-navy px-5 py-2 text-xs font-semibold text-white hover:bg-blue-900"
                >
                    Close
                </button>

            </div>

        </div>

    </div>

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
    MODAL CONTROLS
============================================================= --}}
<script>

document.addEventListener('DOMContentLoaded', function () {

    const openButtons =
        document.querySelectorAll('[data-modal-open]');

    const closeButtons =
        document.querySelectorAll('[data-modal-close]');


    openButtons.forEach(function (button) {

        button.addEventListener('click', function () {

            const modalId =
                this.getAttribute('data-modal-open');

            const modal =
                document.getElementById(modalId);


            if (!modal) {
                return;
            }


            modal.classList.remove('hidden');

            modal.classList.add('flex');

            document.body.classList.add('overflow-hidden');

        });

    });


    closeButtons.forEach(function (button) {

        button.addEventListener('click', function () {

            const modalId =
                this.getAttribute('data-modal-close');

            closeModal(modalId);

        });

    });


    document.querySelectorAll('[id$="-modal"]').forEach(function (modal) {

        modal.addEventListener('click', function (event) {

            if (event.target === modal) {

                closeModal(modal.id);

            }

        });

    });


    document.addEventListener('keydown', function (event) {

        if (event.key === 'Escape') {

            document.querySelectorAll('[id$="-modal"]').forEach(function (modal) {

                if (!modal.classList.contains('hidden')) {

                    closeModal(modal.id);

                }

            });

        }

    });


    function closeModal(modalId) {

        const modal =
            document.getElementById(modalId);


        if (!modal) {
            return;
        }


        modal.classList.add('hidden');

        modal.classList.remove('flex');

        document.body.classList.remove('overflow-hidden');

    }

});

</script>


{{-- =============================================================
    SEARCH PROTOTYPE
============================================================= --}}
<script>

document.addEventListener('DOMContentLoaded', function () {

    const searchButton =
        document.getElementById('search-button');

    const searchInput =
        document.getElementById('visitor-search');


    if (!searchButton || !searchInput) {
        return;
    }


    searchButton.addEventListener('click', function () {

        const value =
            searchInput.value.trim();


        if (!value) {

            searchInput.focus();

            return;

        }


        /*
         * Prototype only:
         * Search functionality will be connected
         * to the Visitor database later.
         */

        console.log('Searching visitor:', value);

    });

});

</script>

@endsection