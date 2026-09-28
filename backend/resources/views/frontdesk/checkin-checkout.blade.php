@extends('layouts.frontdesk')

@section('title', 'Check-In / Check-Out')

@section('content')

<div class="w-full max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8 py-6">

    {{-- =========================================================
        PAGE HEADER
    ========================================================== --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between mb-6">

        <div>
            <h1 class="text-2xl sm:text-3xl font-bold tracking-tight text-primary-navy">
                Check-In / Check-Out
            </h1>

            <p class="mt-1 text-sm text-text-secondary">
                Process visitor entry and exit through QR-based verification.
            </p>
        </div>

        {{-- DATE + LIVE TIME --}}
        <div class="flex items-center gap-5 sm:gap-6 text-right">

            <div>
                <p class="text-[9px] font-semibold uppercase tracking-[0.18em] text-primary-navy">
                    Today
                </p>

                <p id="current-date"
                   class="mt-1 text-xs sm:text-sm font-medium text-text-secondary whitespace-nowrap">
                    Monday, September 28, 2026
                </p>
            </div>

            <div class="h-10 w-px bg-border"></div>

            <div>
                <p class="text-[9px] font-semibold uppercase tracking-[0.18em] text-primary-navy">
                    Current Time
                </p>

                <p id="current-time"
                   class="mt-1 text-base sm:text-lg font-bold text-primary-navy whitespace-nowrap">
                    9:06:00 PM
                </p>
            </div>

        </div>

    </div>


    {{-- =========================================================
        PROCESS TABS
    ========================================================== --}}
    <div class="mb-6">

        <div class="inline-flex w-full max-w-md rounded-xl border border-border bg-white p-1 shadow-sm">

            <button
                type="button"
                id="checkin-tab"
                onclick="switchMode('checkin')"
                class="mode-tab flex-1 rounded-lg bg-primary-navy px-5 py-2.5 text-xs font-semibold text-white transition">
                Check-In
            </button>

            <button
                type="button"
                id="checkout-tab"
                onclick="switchMode('checkout')"
                class="mode-tab flex-1 rounded-lg px-5 py-2.5 text-xs font-semibold text-text-secondary transition hover:bg-slate-50">
                Check-Out
            </button>

        </div>

    </div>


    {{-- =========================================================
        ===================== CHECK-IN ==========================
    ========================================================== --}}
    <div id="checkin-process">

        {{-- PROCESS TITLE --}}
        <div class="mb-5">

            <div class="flex items-center gap-3">

                <span class="inline-flex h-8 items-center rounded-lg bg-primary-navy px-4 text-xs font-bold uppercase tracking-wide text-white">
                    Check-In Process
                </span>

                <span class="hidden sm:block text-xs text-text-secondary">
                    Step-by-step visitor entry verification
                </span>

            </div>

        </div>


        {{-- PROGRESS --}}
        <div class="mb-6 rounded-xl border border-border bg-white px-5 py-4 shadow-sm">

            <div class="flex items-center justify-between gap-2">

                @foreach([
                    ['number' => 1, 'label' => 'Scan QR Code'],
                    ['number' => 2, 'label' => 'Verify Visitor'],
                    ['number' => 3, 'label' => 'ID Surrender'],
                    ['number' => 4, 'label' => 'Confirm Check-In'],
                    ['number' => 5, 'label' => 'Successful']
                ] as $step)

                    <div class="flex min-w-0 flex-1 items-center">

                        <div class="flex min-w-0 items-center gap-2">

                            <span
                                id="checkin-progress-{{ $step['number'] }}"
                                class="checkin-progress-circle flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-600">
                                {{ $step['number'] }}
                            </span>

                            <span class="hidden md:block truncate text-[11px] font-semibold text-text-secondary">
                                {{ $step['label'] }}
                            </span>

                        </div>

                        @if($step['number'] < 5)
                            <div class="mx-2 h-px flex-1 bg-border"></div>
                        @endif

                    </div>

                @endforeach

            </div>

        </div>


        {{-- =====================================================
            CHECK-IN STEP 1
        ====================================================== --}}
        <div id="checkin-step-1" class="checkin-step">

            <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1.15fr)_minmax(300px,0.85fr)]">

                {{-- QR SCANNER --}}
                <section class="rounded-xl border border-border bg-white shadow-sm">

                    <div class="border-b border-border px-5 py-4">

                        <h2 class="text-base font-semibold text-primary-navy">
                            Scan Visitor QR Code
                        </h2>

                        <p class="mt-0.5 text-xs text-text-secondary">
                            Position the visitor's approved QR code within the scanner.
                        </p>

                    </div>


                    <div class="p-5">

                        <div class="rounded-xl border border-dashed border-blue-200 bg-blue-50/40 p-6">

                            <div class="flex flex-col items-center text-center">

                                <div class="flex h-14 w-14 items-center justify-center rounded-xl bg-blue-100 text-blue-600">

                                    <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                        <rect x="3" y="3" width="7" height="7" rx="1"/>
                                        <rect x="14" y="3" width="7" height="7" rx="1"/>
                                        <rect x="3" y="14" width="7" height="7" rx="1"/>
                                        <path d="M14 14h3v3h-3zM18 18h3v3h-3zM18 14h3M14 18v3"/>
                                    </svg>

                                </div>

                                <h3 class="mt-4 text-sm font-semibold text-primary-navy">
                                    Scanner Ready
                                </h3>

                                <p class="mt-1 max-w-md text-xs leading-5 text-text-secondary">
                                    Scan the visitor's approved QR code. The system will automatically retrieve the registered visitor information.
                                </p>


                                <button
                                    type="button"
                                    onclick="simulateCheckinScan()"
                                    class="mt-5 inline-flex min-h-[42px] items-center justify-center rounded-lg bg-primary-navy px-6 py-3 text-xs font-semibold text-white shadow-sm transition hover:opacity-90">

                                    <svg class="mr-2 h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M3 7V5a2 2 0 0 1 2-2h2"/>
                                        <path d="M17 3h2a2 2 0 0 1 2 2v2"/>
                                        <path d="M21 17v2a2 2 0 0 1-2 2h-2"/>
                                        <path d="M7 21H5a2 2 0 0 1-2-2v-2"/>
                                        <rect x="8" y="8" width="8" height="8" rx="1"/>
                                    </svg>

                                    Scan QR Code

                                </button>


                                <div class="my-4 flex w-full max-w-sm items-center gap-3">

                                    <div class="h-px flex-1 bg-border"></div>

                                    <span class="text-[10px] font-medium text-text-secondary">
                                        OR
                                    </span>

                                    <div class="h-px flex-1 bg-border"></div>

                                </div>


                                <button
                                    type="button"
                                    onclick="goToCheckinStep(2)"
                                    class="inline-flex min-h-[40px] items-center justify-center rounded-lg border border-blue-200 bg-white px-5 py-2.5 text-xs font-semibold text-primary-navy transition hover:bg-blue-50">

                                    Manual Verification <span class="ml-1 text-text-secondary">(Backup)</span>

                                </button>

                            </div>

                        </div>


                        <div class="mt-4 rounded-lg border border-blue-100 bg-blue-50/60 px-4 py-3">

                            <p class="text-[10px] leading-4 text-blue-700">
                                <span class="font-semibold">Automated process:</span>
                                Visitor information is retrieved from the approved QR pass. The Front Desk Officer only verifies the displayed information and confirms the required steps.
                            </p>

                        </div>

                    </div>

                </section>


                {{-- VISITOR PREVIEW --}}
                <section class="rounded-xl border border-border bg-white shadow-sm">

                    <div class="border-b border-border px-5 py-4">

                        <h2 class="text-base font-semibold text-primary-navy">
                            Today's Visitor
                        </h2>

                        <p class="mt-0.5 text-xs text-text-secondary">
                            Mock visitor record for prototype presentation.
                        </p>

                    </div>


                    <div class="p-5">

                        <div class="rounded-lg border border-border bg-slate-50 p-4">

                            <p class="text-[9px] font-semibold uppercase tracking-[0.12em] text-text-secondary">
                                Waiting for QR scan
                            </p>

                            <p class="mt-2 text-sm font-semibold text-primary-navy">
                                Maria Santos
                            </p>

                            <p class="mt-0.5 text-xs text-text-secondary">
                                VIS-00124
                            </p>

                        </div>

                        <div class="mt-4 space-y-3">

                            <div class="flex items-center justify-between border-b border-border pb-3">
                                <span class="text-xs text-text-secondary">PDL</span>
                                <span class="text-xs font-semibold text-primary-navy">Juan Dela Cruz</span>
                            </div>

                            <div class="flex items-center justify-between border-b border-border pb-3">
                                <span class="text-xs text-text-secondary">Schedule</span>
                                <span class="text-xs font-semibold text-primary-navy">9:00 AM – 10:00 AM</span>
                            </div>

                            <div class="flex items-center justify-between">
                                <span class="text-xs text-text-secondary">Visit Type</span>
                                <span class="text-xs font-semibold text-primary-navy">Regular Visit</span>
                            </div>

                        </div>

                    </div>

                </section>

            </div>

        </div>


        {{-- =====================================================
            CHECK-IN STEP 2
        ====================================================== --}}
        <div id="checkin-step-2" class="checkin-step hidden">

            <div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1.15fr)_minmax(320px,0.85fr)]">

                {{-- INFORMATION --}}
                <section class="rounded-xl border border-border bg-white shadow-sm">

                    <div class="border-b border-border px-5 py-4">

                        <h2 class="text-base font-semibold text-primary-navy">
                            Visitor Verification
                        </h2>

                        <p class="mt-0.5 text-xs text-text-secondary">
                            Information retrieved from the visitor's approved QR pass.
                        </p>

                    </div>


                    <div class="p-5 space-y-4">

                        {{-- VISITOR INFORMATION --}}
                        <div class="rounded-lg border border-border overflow-hidden">

                            <div class="border-b border-border bg-slate-50 px-4 py-3">

                                <h3 class="text-xs font-semibold text-primary-navy">
                                    Visitor Information
                                </h3>

                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2">

                                @include('frontdesk.partials.info-row', [
                                    'label' => 'Visitor Name',
                                    'value' => 'Maria Santos'
                                ])

                                @include('frontdesk.partials.info-row', [
                                    'label' => 'Visitor ID',
                                    'value' => 'VIS-00124'
                                ])

                                @include('frontdesk.partials.info-row', [
                                    'label' => 'Contact Number',
                                    'value' => '0917 123 4567'
                                ])

                                @include('frontdesk.partials.info-row', [
                                    'label' => 'Relationship to PDL',
                                    'value' => 'Sister'
                                ])

                            </div>

                        </div>


                        {{-- PDL INFORMATION --}}
                        <div class="rounded-lg border border-border overflow-hidden">

                            <div class="border-b border-border bg-slate-50 px-4 py-3">

                                <h3 class="text-xs font-semibold text-primary-navy">
                                    PDL Information
                                </h3>

                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2">

                                @include('frontdesk.partials.info-row', [
                                    'label' => 'PDL Name',
                                    'value' => 'Juan Dela Cruz'
                                ])

                                @include('frontdesk.partials.info-row', [
                                    'label' => 'PDL ID',
                                    'value' => 'PDL-00045'
                                ])

                                @include('frontdesk.partials.info-row', [
                                    'label' => 'Facility',
                                    'value' => 'Main Building'
                                ])

                                @include('frontdesk.partials.info-row', [
                                    'label' => 'Current Status',
                                    'value' => 'Active'
                                ])

                            </div>

                        </div>


                        {{-- APPROVED SCHEDULE --}}
                        <div class="rounded-lg border border-border overflow-hidden">

                            <div class="border-b border-border bg-slate-50 px-4 py-3">

                                <h3 class="text-xs font-semibold text-primary-navy">
                                    Approved Schedule
                                </h3>

                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2">

                                @include('frontdesk.partials.info-row', [
                                    'label' => 'Visit Date',
                                    'value' => 'September 28, 2026'
                                ])

                                @include('frontdesk.partials.info-row', [
                                    'label' => 'Scheduled Time',
                                    'value' => '9:00 AM – 10:00 AM'
                                ])

                                @include('frontdesk.partials.info-row', [
                                    'label' => 'Visit Type',
                                    'value' => 'Regular Visit'
                                ])

                                <div class="px-4 py-3 border-b border-border">
                                    <p class="text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                        Schedule Status
                                    </p>

                                    <span class="mt-1 inline-flex rounded-full bg-emerald-50 px-2.5 py-1 text-[9px] font-semibold text-emerald-600">
                                        Approved
                                    </span>
                                </div>

                            </div>

                        </div>

                    </div>

                </section>


                {{-- VERIFICATION --}}
                <section class="rounded-xl border border-border bg-white shadow-sm">

                    <div class="border-b border-border px-5 py-4">

                        <h2 class="text-base font-semibold text-primary-navy">
                            Verification Results
                        </h2>

                        <p class="mt-0.5 text-xs text-text-secondary">
                            System automatically checks the visitor's eligibility.
                        </p>

                    </div>


                    <div class="p-5">

                        <div class="space-y-2">

                            @foreach([
                                ['Visitor Identity', 'Verified'],
                                ['Approved Schedule', 'Within Schedule'],
                                ['QR Code', 'Valid'],
                                ['Registered ID', 'Matched'],
                                ['PDL Eligibility', 'Eligible'],
                                ['Watchlist Check', 'No Match'],
                                ['Visit Frequency', 'Within Limit']
                            ] as $verification)

                                <div class="flex items-center justify-between gap-3 rounded-lg border border-border bg-white px-3 py-3">

                                    <div class="flex items-center gap-2">

                                        <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">

                                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                                <path d="m5 12 4 4L19 6"/>
                                            </svg>

                                        </span>

                                        <span class="text-xs font-medium text-text-primary">
                                            {{ $verification[0] }}
                                        </span>

                                    </div>

                                    <span class="text-[9px] font-semibold text-emerald-600">
                                        {{ $verification[1] }}
                                    </span>

                                </div>

                            @endforeach

                        </div>


                        <div class="mt-4 rounded-lg border border-emerald-100 bg-emerald-50 p-4">

                            <div class="flex items-start gap-3">

                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">

                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <path d="m5 12 4 4L19 6"/>
                                    </svg>

                                </span>

                                <div>

                                    <p class="text-xs font-bold text-emerald-700">
                                        Eligible for Check-In
                                    </p>

                                    <p class="mt-0.5 text-[10px] leading-4 text-emerald-600">
                                        All required verification checks have passed.
                                    </p>

                                </div>

                            </div>

                        </div>

                    </div>

                </section>

            </div>


            {{-- ACTIONS --}}
            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">

                <button
                    type="button"
                    onclick="goToCheckinStep(1)"
                    class="min-h-[42px] rounded-lg border border-border bg-white px-5 py-2.5 text-xs font-semibold text-text-primary hover:bg-slate-50">
                    Back
                </button>

                <button
                    type="button"
                    onclick="goToCheckinStep(3)"
                    class="min-h-[42px] rounded-lg bg-primary-navy px-6 py-2.5 text-xs font-semibold text-white hover:opacity-90">
                    Proceed to ID Surrender →
                </button>

            </div>

        </div>


        {{-- =====================================================
            CHECK-IN STEP 3
        ====================================================== --}}
        <div id="checkin-step-3" class="checkin-step hidden">

            <section class="mx-auto max-w-3xl rounded-xl border border-border bg-white shadow-sm">

                <div class="border-b border-border px-5 py-4">

                    <h2 class="text-base font-semibold text-primary-navy">
                        ID Surrender Confirmation
                    </h2>

                    <p class="mt-0.5 text-xs text-text-secondary">
                        Confirm that the visitor's registered identification card has been surrendered.
                    </p>

                </div>


                <div class="p-5">

                    <div class="rounded-lg border border-blue-100 bg-blue-50/50 p-4 mb-5">

                        <div class="flex items-start gap-3">

                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-blue-100 text-blue-600">

                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                    <rect x="3" y="5" width="18" height="14" rx="2"/>
                                    <path d="M7 9h4M7 13h7"/>
                                </svg>

                            </div>

                            <div>

                                <p class="text-xs font-semibold text-primary-navy">
                                    Registered ID Found
                                </p>

                                <p class="mt-0.5 text-[10px] leading-4 text-text-secondary">
                                    The ID information below was retrieved from the visitor's registered account.
                                </p>

                            </div>

                        </div>

                    </div>


                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">

                        <div>
                            <label class="text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                ID Type
                            </label>

                            <div class="mt-1.5 flex min-h-[42px] items-center rounded-lg border border-border bg-slate-50 px-3 text-xs text-text-primary">
                                Philippine National ID
                            </div>
                        </div>


                        <div>
                            <label class="text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                ID Number
                            </label>

                            <div class="mt-1.5 flex min-h-[42px] items-center rounded-lg border border-border bg-slate-50 px-3 text-xs text-text-primary">
                                ********4821
                            </div>
                        </div>


                        <div>
                            <label class="text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                Registered Name
                            </label>

                            <div class="mt-1.5 flex min-h-[42px] items-center rounded-lg border border-border bg-slate-50 px-3 text-xs text-text-primary">
                                Maria Santos
                            </div>
                        </div>


                        <div>
                            <label class="text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                Surrendered By
                            </label>

                            <div class="mt-1.5 flex min-h-[42px] items-center rounded-lg border border-border bg-slate-50 px-3 text-xs text-text-primary">
                                Front Desk Officer
                            </div>
                        </div>

                    </div>


                    <label class="mt-5 flex cursor-pointer items-start gap-3 rounded-lg border border-border bg-white p-4">

                        <input
                            id="checkin-id-confirmed"
                            type="checkbox"
                            class="mt-0.5 h-4 w-4 rounded border-border text-blue-600 focus:ring-blue-500"
                            checked>

                        <span>

                            <span class="block text-xs font-semibold text-text-primary">
                                ID has been physically surrendered.
                            </span>

                            <span class="mt-0.5 block text-[10px] text-text-secondary">
                                Confirm only after receiving the visitor's registered ID.
                            </span>

                        </span>

                    </label>

                </div>

            </section>


            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">

                <button
                    type="button"
                    onclick="goToCheckinStep(2)"
                    class="min-h-[42px] rounded-lg border border-border bg-white px-5 py-2.5 text-xs font-semibold text-text-primary hover:bg-slate-50">
                    Back
                </button>

                <button
                    type="button"
                    onclick="goToCheckinStep(4)"
                    class="min-h-[42px] rounded-lg bg-primary-navy px-6 py-2.5 text-xs font-semibold text-white hover:opacity-90">
                    Next →
                </button>

            </div>

        </div>


        {{-- =====================================================
            CHECK-IN STEP 4
        ====================================================== --}}
        <div id="checkin-step-4" class="checkin-step hidden">

            <section class="mx-auto max-w-4xl rounded-xl border border-border bg-white shadow-sm">

                <div class="border-b border-border px-5 py-4">

                    <h2 class="text-base font-semibold text-primary-navy">
                        Confirm Visitor Check-In
                    </h2>

                    <p class="mt-0.5 text-xs text-text-secondary">
                        Review the automatically retrieved information before finalizing the check-in.
                    </p>

                </div>


                <div class="p-5">

                    <div class="grid grid-cols-1 gap-5 md:grid-cols-[180px_minmax(0,1fr)]">

                        {{-- VISITOR --}}
                        <div class="flex flex-col items-center justify-center rounded-lg border border-border bg-slate-50 p-5 text-center">

                            <div class="flex h-20 w-20 items-center justify-center rounded-full bg-blue-100 text-xl font-bold text-primary-navy">
                                MS
                            </div>

                            <p class="mt-3 text-sm font-bold text-primary-navy">
                                Maria Santos
                            </p>

                            <span class="mt-1 inline-flex rounded-full bg-emerald-50 px-2.5 py-1 text-[9px] font-semibold text-emerald-600">
                                Verified
                            </span>

                        </div>


                        {{-- SUMMARY --}}
                        <div class="space-y-3">

                            @foreach([
                                ['Visitor ID', 'VIS-00124'],
                                ['PDL Name', 'Juan Dela Cruz'],
                                ['PDL ID', 'PDL-00045'],
                                ['Scheduled Time', '9:00 AM – 10:00 AM'],
                                ['Current Time', '9:08 AM'],
                                ['Visit Type', 'Regular Visit'],
                                ['Verification Status', 'Eligible'],
                                ['ID Status', 'Surrendered']
                            ] as $item)

                                <div class="flex flex-col gap-1 rounded-lg border border-border px-4 py-3 sm:flex-row sm:items-center sm:justify-between">

                                    <span class="text-[10px] font-semibold uppercase tracking-wide text-text-secondary">
                                        {{ $item[0] }}
                                    </span>

                                    <span class="text-xs font-semibold text-text-primary">
                                        {{ $item[1] }}
                                    </span>

                                </div>

                            @endforeach

                        </div>

                    </div>


                    <div class="mt-5 rounded-lg border border-emerald-100 bg-emerald-50 p-4">

                        <div class="flex items-center gap-3">

                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">

                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                    <path d="m5 12 4 4L19 6"/>
                                </svg>

                            </span>

                            <div>

                                <p class="text-xs font-semibold text-emerald-700">
                                    All verification requirements have been completed.
                                </p>

                                <p class="text-[10px] text-emerald-600">
                                    Visitor is ready to be officially checked in.
                                </p>

                            </div>

                        </div>

                    </div>

                </div>

            </section>


            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">

                <button
                    type="button"
                    onclick="goToCheckinStep(3)"
                    class="min-h-[42px] rounded-lg border border-border bg-white px-5 py-2.5 text-xs font-semibold text-text-primary hover:bg-slate-50">
                    Back
                </button>

                <button
                    type="button"
                    onclick="goToCheckinStep(5)"
                    class="min-h-[42px] rounded-lg bg-primary-navy px-6 py-2.5 text-xs font-semibold text-white hover:opacity-90">
                    Confirm Check-In →
                </button>

            </div>

        </div>


        {{-- =====================================================
            CHECK-IN STEP 5
        ====================================================== --}}
        <div id="checkin-step-5" class="checkin-step hidden">

            <section class="mx-auto max-w-xl rounded-xl border border-border bg-white shadow-sm">

                <div class="p-7 text-center sm:p-9">

                    <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">

                        <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path d="m5 12 4 4L19 6"/>
                        </svg>

                    </div>

                    <h2 class="mt-5 text-lg font-bold text-primary-navy">
                        Check-In Successful
                    </h2>

                    <p class="mt-1 text-xs text-text-secondary">
                        Maria Santos has been successfully checked in.
                    </p>


                    <div class="mt-6 rounded-lg border border-border bg-slate-50 p-4 text-left">

                        @foreach([
                            ['Visitor Name', 'Maria Santos'],
                            ['PDL Name', 'Juan Dela Cruz'],
                            ['Check-In Time', '8:52 AM'],
                            ['Visit Type', 'Regular Visit'],
                            ['ID Status', 'Surrendered'],
                            ['Visit Status', 'Currently Inside']
                        ] as $item)

                            <div class="flex items-center justify-between border-b border-border py-2.5 last:border-0">

                                <span class="text-[10px] text-text-secondary">
                                    {{ $item[0] }}
                                </span>

                                <span class="text-xs font-semibold text-text-primary">
                                    {{ $item[1] }}
                                </span>

                            </div>

                        @endforeach

                    </div>


                    <div class="mt-5 flex flex-col gap-2 sm:flex-row">

                        <button
                            type="button"
                            onclick="resetCheckin()"
                            class="min-h-[42px] flex-1 rounded-lg border border-border bg-white px-5 py-2.5 text-xs font-semibold text-text-primary hover:bg-slate-50">
                            Scan Another Visitor
                        </button>

                        <button
                            type="button"
                            onclick="switchMode('checkout')"
                            class="min-h-[42px] flex-1 rounded-lg bg-primary-navy px-5 py-2.5 text-xs font-semibold text-white hover:opacity-90">
                            Go to Check-Out
                        </button>

                    </div>

                </div>

            </section>

        </div>

    </div>


    {{-- =========================================================
        ===================== CHECK-OUT =========================
    ========================================================== --}}
    <div id="checkout-process" class="hidden">

        {{-- PROCESS TITLE --}}
        <div class="mb-5">

            <div class="flex items-center gap-3">

                <span class="inline-flex h-8 items-center rounded-lg bg-primary-navy px-4 text-xs font-bold uppercase tracking-wide text-white">
                    Check-Out Process
                </span>

                <span class="hidden sm:block text-xs text-text-secondary">
                    Step-by-step visitor exit verification
                </span>

            </div>

        </div>


        {{-- PROGRESS --}}
        <div class="mb-6 rounded-xl border border-border bg-white px-5 py-4 shadow-sm">

            <div class="flex items-center justify-between gap-2">

                @foreach([
                    ['number' => 1, 'label' => 'Scan QR Code'],
                    ['number' => 2, 'label' => 'Active Visit'],
                    ['number' => 3, 'label' => 'ID Return'],
                    ['number' => 4, 'label' => 'Confirm Check-Out'],
                    ['number' => 5, 'label' => 'Successful']
                ] as $step)

                    <div class="flex min-w-0 flex-1 items-center">

                        <div class="flex min-w-0 items-center gap-2">

                            <span
                                id="checkout-progress-{{ $step['number'] }}"
                                class="checkout-progress-circle flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-600">
                                {{ $step['number'] }}
                            </span>

                            <span class="hidden md:block truncate text-[11px] font-semibold text-text-secondary">
                                {{ $step['label'] }}
                            </span>

                        </div>

                        @if($step['number'] < 5)
                            <div class="mx-2 h-px flex-1 bg-border"></div>
                        @endif

                    </div>

                @endforeach

            </div>

        </div>


        {{-- =====================================================
            CHECK-OUT STEP 1
        ====================================================== --}}
        <div id="checkout-step-1" class="checkout-step">

            <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1.15fr)_minmax(300px,0.85fr)]">

                <section class="rounded-xl border border-border bg-white shadow-sm">

                    <div class="border-b border-border px-5 py-4">

                        <h2 class="text-base font-semibold text-primary-navy">
                            Scan Visitor QR Code
                        </h2>

                        <p class="mt-0.5 text-xs text-text-secondary">
                            Scan the same QR code to locate the visitor's active visit.
                        </p>

                    </div>


                    <div class="p-5">

                        <div class="rounded-xl border border-dashed border-blue-200 bg-blue-50/40 p-6">

                            <div class="flex flex-col items-center text-center">

                                <div class="flex h-14 w-14 items-center justify-center rounded-xl bg-blue-100 text-blue-600">

                                    <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                        <rect x="3" y="3" width="7" height="7" rx="1"/>
                                        <rect x="14" y="3" width="7" height="7" rx="1"/>
                                        <rect x="3" y="14" width="7" height="7" rx="1"/>
                                        <path d="M14 14h3v3h-3zM18 18h3v3h-3zM18 14h3M14 18v3"/>
                                    </svg>

                                </div>

                                <h3 class="mt-4 text-sm font-semibold text-primary-navy">
                                    Scanner Ready
                                </h3>

                                <p class="mt-1 max-w-md text-xs leading-5 text-text-secondary">
                                    Scan the visitor's QR code. The system will locate the active visit automatically.
                                </p>


                                <button
                                    type="button"
                                    onclick="goToCheckoutStep(2)"
                                    class="mt-5 inline-flex min-h-[42px] items-center justify-center rounded-lg bg-primary-navy px-6 py-3 text-xs font-semibold text-white shadow-sm transition hover:opacity-90">

                                    <svg class="mr-2 h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="3" width="18" height="18" rx="2"/>
                                        <path d="M8 8h3v3H8zM13 8h3v3h-3zM8 13h3v3H8zM13 13h3v3h-3z"/>
                                    </svg>

                                    Scan QR Code

                                </button>


                                <div class="my-4 flex w-full max-w-sm items-center gap-3">

                                    <div class="h-px flex-1 bg-border"></div>

                                    <span class="text-[10px] font-medium text-text-secondary">
                                        OR
                                    </span>

                                    <div class="h-px flex-1 bg-border"></div>

                                </div>


                                <button
                                    type="button"
                                    onclick="goToCheckoutStep(2)"
                                    class="inline-flex min-h-[40px] items-center justify-center rounded-lg border border-blue-200 bg-white px-5 py-2.5 text-xs font-semibold text-primary-navy transition hover:bg-blue-50">
                                    Manual Verification <span class="ml-1 text-text-secondary">(Backup)</span>
                                </button>

                            </div>

                        </div>


                        <div class="mt-4 rounded-lg border border-blue-100 bg-blue-50/60 px-4 py-3">

                            <p class="text-[10px] leading-4 text-blue-700">
                                <span class="font-semibold">Automated process:</span>
                                The system finds the visitor's active visit using the QR pass.
                            </p>

                        </div>

                    </div>

                </section>


                <section class="rounded-xl border border-border bg-white shadow-sm">

                    <div class="border-b border-border px-5 py-4">

                        <h2 class="text-base font-semibold text-primary-navy">
                            Current Active Visit
                        </h2>

                        <p class="mt-0.5 text-xs text-text-secondary">
                            Mock active visit for prototype presentation.
                        </p>

                    </div>


                    <div class="p-5">

                        <div class="rounded-lg border border-emerald-100 bg-emerald-50 p-4">

                            <span class="inline-flex rounded-full bg-emerald-100 px-2.5 py-1 text-[9px] font-semibold text-emerald-700">
                                Currently Checked-In
                            </span>

                            <p class="mt-3 text-sm font-bold text-primary-navy">
                                Maria Santos
                            </p>

                            <p class="mt-0.5 text-xs text-text-secondary">
                                VIS-00124
                            </p>

                        </div>


                        <div class="mt-4 space-y-3">

                            <div class="flex items-center justify-between border-b border-border pb-3">
                                <span class="text-xs text-text-secondary">PDL</span>
                                <span class="text-xs font-semibold text-primary-navy">Juan Dela Cruz</span>
                            </div>

                            <div class="flex items-center justify-between border-b border-border pb-3">
                                <span class="text-xs text-text-secondary">Check-In</span>
                                <span class="text-xs font-semibold text-primary-navy">8:52 AM</span>
                            </div>

                            <div class="flex items-center justify-between">
                                <span class="text-xs text-text-secondary">ID Status</span>
                                <span class="text-xs font-semibold text-emerald-600">Surrendered</span>
                            </div>

                        </div>

                    </div>

                </section>

            </div>

        </div>


        {{-- =====================================================
            CHECK-OUT STEP 2
        ====================================================== --}}
        <div id="checkout-step-2" class="checkout-step hidden">

            <section class="mx-auto max-w-4xl rounded-xl border border-border bg-white shadow-sm">

                <div class="border-b border-border px-5 py-4">

                    <h2 class="text-base font-semibold text-primary-navy">
                        Active Visit Found
                    </h2>

                    <p class="mt-0.5 text-xs text-text-secondary">
                        The system located the visitor's current active visit.
                    </p>

                </div>


                <div class="p-5">

                    <div class="rounded-lg border border-emerald-100 bg-emerald-50 p-4 mb-5">

                        <div class="flex items-center justify-between gap-3">

                            <div>

                                <p class="text-sm font-bold text-primary-navy">
                                    Maria Santos
                                </p>

                                <p class="mt-0.5 text-xs text-text-secondary">
                                    VIS-00124
                                </p>

                            </div>

                            <span class="shrink-0 rounded-full bg-emerald-100 px-3 py-1 text-[9px] font-semibold text-emerald-700">
                                Currently Checked-In
                            </span>

                        </div>

                    </div>


                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">

                        @foreach([
                            ['Visitor Name', 'Maria Santos'],
                            ['Visitor ID', 'VIS-00124'],
                            ['PDL Name', 'Juan Dela Cruz'],
                            ['PDL ID', 'PDL-00045'],
                            ['Check-In Time', '8:52 AM'],
                            ['Visit Type', 'Regular Visit'],
                            ['ID Status', 'Surrendered'],
                            ['Current Duration', '1 hour, 6 minutes']
                        ] as $item)

                            <div class="rounded-lg border border-border px-4 py-3">

                                <p class="text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                    {{ $item[0] }}
                                </p>

                                <p class="mt-1 text-xs font-semibold text-text-primary">
                                    {{ $item[1] }}
                                </p>

                            </div>

                        @endforeach

                    </div>

                </div>

            </section>


            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">

                <button
                    type="button"
                    onclick="goToCheckoutStep(1)"
                    class="min-h-[42px] rounded-lg border border-border bg-white px-5 py-2.5 text-xs font-semibold text-text-primary hover:bg-slate-50">
                    Back
                </button>

                <button
                    type="button"
                    onclick="goToCheckoutStep(3)"
                    class="min-h-[42px] rounded-lg bg-primary-navy px-6 py-2.5 text-xs font-semibold text-white hover:opacity-90">
                    Proceed to ID Return →
                </button>

            </div>

        </div>


        {{-- =====================================================
            CHECK-OUT STEP 3
        ====================================================== --}}
        <div id="checkout-step-3" class="checkout-step hidden">

            <section class="mx-auto max-w-3xl rounded-xl border border-border bg-white shadow-sm">

                <div class="border-b border-border px-5 py-4">

                    <h2 class="text-base font-semibold text-primary-navy">
                        ID Return Confirmation
                    </h2>

                    <p class="mt-0.5 text-xs text-text-secondary">
                        Confirm that the visitor's registered identification card has been returned.
                    </p>

                </div>


                <div class="p-5">

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">

                        <div>
                            <label class="text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                ID Type
                            </label>

                            <div class="mt-1.5 flex min-h-[42px] items-center rounded-lg border border-border bg-slate-50 px-3 text-xs text-text-primary">
                                Philippine National ID
                            </div>
                        </div>


                        <div>
                            <label class="text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                ID Number
                            </label>

                            <div class="mt-1.5 flex min-h-[42px] items-center rounded-lg border border-border bg-slate-50 px-3 text-xs text-text-primary">
                                ********4821
                            </div>
                        </div>


                        <div>
                            <label class="text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                Registered Name
                            </label>

                            <div class="mt-1.5 flex min-h-[42px] items-center rounded-lg border border-border bg-slate-50 px-3 text-xs text-text-primary">
                                Maria Santos
                            </div>
                        </div>


                        <div>
                            <label class="text-[9px] font-semibold uppercase tracking-wide text-text-secondary">
                                Returned To
                            </label>

                            <div class="mt-1.5 flex min-h-[42px] items-center rounded-lg border border-border bg-slate-50 px-3 text-xs text-text-primary">
                                Maria Santos
                            </div>
                        </div>

                    </div>


                    <label class="mt-5 flex cursor-pointer items-start gap-3 rounded-lg border border-border bg-white p-4">

                        <input
                            id="checkout-id-confirmed"
                            type="checkbox"
                            class="mt-0.5 h-4 w-4 rounded border-border text-blue-600 focus:ring-blue-500"
                            checked>

                        <span>

                            <span class="block text-xs font-semibold text-text-primary">
                                ID has been returned to the visitor.
                            </span>

                            <span class="mt-0.5 block text-[10px] text-text-secondary">
                                Confirm only after physically returning the registered ID.
                            </span>

                        </span>

                    </label>

                </div>

            </section>


            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">

                <button
                    type="button"
                    onclick="goToCheckoutStep(2)"
                    class="min-h-[42px] rounded-lg border border-border bg-white px-5 py-2.5 text-xs font-semibold text-text-primary hover:bg-slate-50">
                    Back
                </button>

                <button
                    type="button"
                    onclick="goToCheckoutStep(4)"
                    class="min-h-[42px] rounded-lg bg-primary-navy px-6 py-2.5 text-xs font-semibold text-white hover:opacity-90">
                    Next →
                </button>

            </div>

        </div>


        {{-- =====================================================
            CHECK-OUT STEP 4
        ====================================================== --}}
        <div id="checkout-step-4" class="checkout-step hidden">

            <section class="mx-auto max-w-4xl rounded-xl border border-border bg-white shadow-sm">

                <div class="border-b border-border px-5 py-4">

                    <h2 class="text-base font-semibold text-primary-navy">
                        Confirm Visitor Check-Out
                    </h2>

                    <p class="mt-0.5 text-xs text-text-secondary">
                        Review the visit details before completing the check-out.
                    </p>

                </div>


                <div class="p-5">

                    <div class="grid grid-cols-1 gap-5 md:grid-cols-[180px_minmax(0,1fr)]">

                        <div class="flex flex-col items-center justify-center rounded-lg border border-border bg-slate-50 p-5 text-center">

                            <div class="flex h-20 w-20 items-center justify-center rounded-full bg-blue-100 text-xl font-bold text-primary-navy">
                                MS
                            </div>

                            <p class="mt-3 text-sm font-bold text-primary-navy">
                                Maria Santos
                            </p>

                            <span class="mt-1 inline-flex rounded-full bg-emerald-50 px-2.5 py-1 text-[9px] font-semibold text-emerald-600">
                                Active Visit
                            </span>

                        </div>


                        <div class="space-y-3">

                            @foreach([
                                ['Visitor ID', 'VIS-00124'],
                                ['PDL Name', 'Juan Dela Cruz'],
                                ['Check-In Time', '8:52 AM'],
                                ['Current Time', '9:58 AM'],
                                ['Visit Duration', '1 hour, 6 minutes'],
                                ['Visit Type', 'Regular Visit'],
                                ['ID Status', 'Returned'],
                                ['Visit Status', 'Ready to Complete']
                            ] as $item)

                                <div class="flex flex-col gap-1 rounded-lg border border-border px-4 py-3 sm:flex-row sm:items-center sm:justify-between">

                                    <span class="text-[10px] font-semibold uppercase tracking-wide text-text-secondary">
                                        {{ $item[0] }}
                                    </span>

                                    <span class="text-xs font-semibold text-text-primary">
                                        {{ $item[1] }}
                                    </span>

                                </div>

                            @endforeach

                        </div>

                    </div>


                    <div class="mt-5 rounded-lg border border-emerald-100 bg-emerald-50 p-4">

                        <div class="flex items-center gap-3">

                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">

                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                    <path d="m5 12 4 4L19 6"/>
                                </svg>

                            </span>

                            <div>

                                <p class="text-xs font-semibold text-emerald-700">
                                    All check-out requirements have been completed.
                                </p>

                                <p class="text-[10px] text-emerald-600">
                                    The visitor is ready to be checked out.
                                </p>

                            </div>

                        </div>

                    </div>

                </div>

            </section>


            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">

                <button
                    type="button"
                    onclick="goToCheckoutStep(3)"
                    class="min-h-[42px] rounded-lg border border-border bg-white px-5 py-2.5 text-xs font-semibold text-text-primary hover:bg-slate-50">
                    Back
                </button>

                <button
                    type="button"
                    onclick="goToCheckoutStep(5)"
                    class="min-h-[42px] rounded-lg bg-primary-navy px-6 py-2.5 text-xs font-semibold text-white hover:opacity-90">
                    Confirm Check-Out →
                </button>

            </div>

        </div>


        {{-- =====================================================
            CHECK-OUT STEP 5
        ====================================================== --}}
        <div id="checkout-step-5" class="checkout-step hidden">

            <section class="mx-auto max-w-xl rounded-xl border border-border bg-white shadow-sm">

                <div class="p-7 text-center sm:p-9">

                    <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">

                        <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path d="m5 12 4 4L19 6"/>
                        </svg>

                    </div>

                    <h2 class="mt-5 text-lg font-bold text-primary-navy">
                        Check-Out Successful
                    </h2>

                    <p class="mt-1 text-xs text-text-secondary">
                        Maria Santos has been successfully checked out.
                    </p>


                    <div class="mt-6 rounded-lg border border-border bg-slate-50 p-4 text-left">

                        @foreach([
                            ['Visitor Name', 'Maria Santos'],
                            ['PDL Name', 'Juan Dela Cruz'],
                            ['Check-In Time', '8:52 AM'],
                            ['Check-Out Time', '9:58 AM'],
                            ['Visit Duration', '1 hour, 6 minutes'],
                            ['ID Status', 'Returned'],
                            ['Visit Status', 'Completed']
                        ] as $item)

                            <div class="flex items-center justify-between border-b border-border py-2.5 last:border-0">

                                <span class="text-[10px] text-text-secondary">
                                    {{ $item[0] }}
                                </span>

                                <span class="text-xs font-semibold text-text-primary">
                                    {{ $item[1] }}
                                </span>

                            </div>

                        @endforeach

                    </div>


                    <div class="mt-5 flex flex-col gap-2 sm:flex-row">

                        <button
                            type="button"
                            onclick="resetCheckout()"
                            class="min-h-[42px] flex-1 rounded-lg border border-border bg-white px-5 py-2.5 text-xs font-semibold text-text-primary hover:bg-slate-50">
                            Scan Another Visitor
                        </button>

                        <button
                            type="button"
                            onclick="switchMode('checkin')"
                            class="min-h-[42px] flex-1 rounded-lg bg-primary-navy px-5 py-2.5 text-xs font-semibold text-white hover:opacity-90">
                            Go to Check-In
                        </button>

                    </div>

                </div>

            </section>

        </div>

    </div>

</div>


{{-- =============================================================
    LIVE DATE + TIME
============================================================= --}}
<script>

    function updateFrontDeskDateTime() {

        const now = new Date();

        const dateElement = document.getElementById('current-date');
        const timeElement = document.getElementById('current-time');

        if (!dateElement || !timeElement) return;

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


    /* =========================================================
       MODE SWITCH
    ========================================================== */

    function switchMode(mode) {

        const checkinProcess = document.getElementById('checkin-process');
        const checkoutProcess = document.getElementById('checkout-process');

        const checkinTab = document.getElementById('checkin-tab');
        const checkoutTab = document.getElementById('checkout-tab');

        if (mode === 'checkin') {

            checkinProcess.classList.remove('hidden');
            checkoutProcess.classList.add('hidden');

            checkinTab.classList.add('bg-primary-navy', 'text-white');
            checkinTab.classList.remove('text-text-secondary');

            checkoutTab.classList.remove('bg-primary-navy', 'text-white');
            checkoutTab.classList.add('text-text-secondary');

        } else {

            checkinProcess.classList.add('hidden');
            checkoutProcess.classList.remove('hidden');

            checkoutTab.classList.add('bg-primary-navy', 'text-white');
            checkoutTab.classList.remove('text-text-secondary');

            checkinTab.classList.remove('bg-primary-navy', 'text-white');
            checkinTab.classList.add('text-text-secondary');

        }

    }


    /* =========================================================
       CHECK-IN PROCESS
    ========================================================== */

    function goToCheckinStep(step) {

        document.querySelectorAll('.checkin-step').forEach(function(element) {
            element.classList.add('hidden');
        });

        const target = document.getElementById('checkin-step-' + step);

        if (target) {
            target.classList.remove('hidden');
        }

        updateCheckinProgress(step);

        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });

    }


    function updateCheckinProgress(activeStep) {

        for (let i = 1; i <= 5; i++) {

            const circle = document.getElementById('checkin-progress-' + i);

            if (!circle) continue;

            if (i <= activeStep) {

                circle.classList.remove(
                    'bg-blue-100',
                    'text-blue-600'
                );

                circle.classList.add(
                    'bg-blue-600',
                    'text-white'
                );

            } else {

                circle.classList.remove(
                    'bg-blue-600',
                    'text-white'
                );

                circle.classList.add(
                    'bg-blue-100',
                    'text-blue-600'
                );

            }

        }

    }


    function simulateCheckinScan() {

        const button = event.currentTarget;

        button.disabled = true;

        button.innerHTML = `
            <svg class="mr-2 h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity=".25"/>
                <path d="M21 12a9 9 0 0 1-9 9" stroke="currentColor" stroke-width="3"/>
            </svg>
            Scanning...
        `;

        setTimeout(function() {

            button.disabled = false;

            button.innerHTML = `
                <svg class="mr-2 h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="18" height="18" rx="2"/>
                    <path d="M8 8h3v3H8zM13 8h3v3h-3zM8 13h3v3H8zM13 13h3v3h-3z"/>
                </svg>
                Scan QR Code
            `;

            goToCheckinStep(2);

        }, 700);

    }


    function resetCheckin() {

        goToCheckinStep(1);

    }


    /* =========================================================
       CHECK-OUT PROCESS
    ========================================================== */

    function goToCheckoutStep(step) {

        document.querySelectorAll('.checkout-step').forEach(function(element) {
            element.classList.add('hidden');
        });

        const target = document.getElementById('checkout-step-' + step);

        if (target) {
            target.classList.remove('hidden');
        }

        updateCheckoutProgress(step);

        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });

    }


    function updateCheckoutProgress(activeStep) {

        for (let i = 1; i <= 5; i++) {

            const circle = document.getElementById('checkout-progress-' + i);

            if (!circle) continue;

            if (i <= activeStep) {

                circle.classList.remove(
                    'bg-blue-100',
                    'text-blue-600'
                );

                circle.classList.add(
                    'bg-blue-600',
                    'text-white'
                );

            } else {

                circle.classList.remove(
                    'bg-blue-600',
                    'text-white'
                );

                circle.classList.add(
                    'bg-blue-100',
                    'text-blue-600'
                );

            }

        }

    }


    function resetCheckout() {

        goToCheckoutStep(1);

    }


    /* =========================================================
       INITIAL STATE
    ========================================================== */

    document.addEventListener('DOMContentLoaded', function() {

        switchMode('checkin');

        goToCheckinStep(1);

        goToCheckoutStep(1);

    });

</script>

@endsection