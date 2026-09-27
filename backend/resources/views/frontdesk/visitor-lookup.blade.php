@extends('layouts.frontdesk')

@section('title', 'Visitor Lookup')

@section('content')

    {{-- =========================================================
        PAGE HEADER
    ========================================================== --}}
    <div class="flex flex-col gap-sm sm:flex-row sm:items-center sm:justify-between">

        <div>
            <p class="text-body text-text-secondary">
                Search and view visitor information and visit history.
            </p>
        </div>

        <div class="rounded-chip border border-border bg-card px-md py-sm">
            <p class="text-status-label uppercase tracking-wide text-text-secondary">
                Visitor Records
            </p>

            <p class="text-body font-semibold text-text-primary">
                Front Desk
            </p>
        </div>

    </div>


    {{-- =========================================================
        SEARCH SECTION
    ========================================================== --}}
    <section class="cc-card">

        <div class="mb-md">

            <h2 class="text-card-title font-semibold text-text-primary">
                Search Visitor
            </h2>

            <p class="mt-xs text-status-label text-text-secondary">
                Search using the visitor's name, visitor ID, or contact number.
            </p>

        </div>


        <div class="flex flex-col gap-sm sm:flex-row">

            <input
                type="text"
                placeholder="Enter visitor name or visitor ID..."
                class="w-full rounded-chip border border-border bg-background px-md py-sm text-body text-text-primary outline-none transition placeholder:text-text-secondary focus:border-primary-teal focus:ring-1 focus:ring-primary-teal sm:flex-1"
            >

            <button
                type="button"
                class="rounded-chip bg-primary-navy px-lg py-sm text-body font-semibold text-white transition hover:opacity-90"
            >
                Search
            </button>

            <button
                type="button"
                class="rounded-chip border border-border bg-card px-lg py-sm text-body font-semibold text-text-secondary transition hover:bg-background"
            >
                Clear
            </button>

        </div>

    </section>


    {{-- =========================================================
        VISITOR INFORMATION
    ========================================================== --}}
    <section class="cc-card">

        <div class="border-b border-border pb-md">

            <h2 class="text-card-title font-semibold text-text-primary">
                Visitor Information
            </h2>

            <p class="mt-xs text-status-label text-text-secondary">
                Selected visitor information
            </p>

        </div>


        <div class="grid grid-cols-1 gap-lg pt-lg md:grid-cols-2 xl:grid-cols-4">

            {{-- Visitor Name --}}
            <div>
                <p class="text-status-label uppercase tracking-wide text-text-secondary">
                    Visitor Name
                </p>

                <p class="mt-xs text-body font-semibold text-text-primary">
                    Maria Santos
                </p>
            </div>


            {{-- Visitor ID --}}
            <div>
                <p class="text-status-label uppercase tracking-wide text-text-secondary">
                    Visitor ID
                </p>

                <p class="mt-xs text-body font-semibold text-text-primary">
                    VIS-00124
                </p>
            </div>


            {{-- Contact Number --}}
            <div>
                <p class="text-status-label uppercase tracking-wide text-text-secondary">
                    Contact Number
                </p>

                <p class="mt-xs text-body text-text-primary">
                    0917 123 4567
                </p>
            </div>


            {{-- Visitor Status --}}
            <div>
                <p class="text-status-label uppercase tracking-wide text-text-secondary">
                    Visitor Status
                </p>

                <span class="mt-xs inline-flex rounded-chip bg-success/10 px-sm py-xs text-status-label font-semibold text-success">
                    Eligible
                </span>
            </div>

        </div>

    </section>


    {{-- =========================================================
        VISIT HISTORY
    ========================================================== --}}
    <section class="cc-card overflow-hidden p-0">

        {{-- Table Header --}}
        <div class="border-b border-border px-lg py-md">

            <h2 class="text-card-title font-semibold text-text-primary">
                Visit History
            </h2>

            <p class="mt-xs text-status-label text-text-secondary">
                Previous and current visits associated with this visitor.
            </p>

        </div>


        {{-- Table --}}
        <div class="overflow-x-auto">

            <table class="w-full text-left">

                <thead class="border-b border-border bg-background">

                    <tr>

                        <th class="px-lg py-md text-status-label font-semibold uppercase tracking-wide text-text-secondary">
                            Date
                        </th>

                        <th class="px-lg py-md text-status-label font-semibold uppercase tracking-wide text-text-secondary">
                            PDL
                        </th>

                        <th class="px-lg py-md text-status-label font-semibold uppercase tracking-wide text-text-secondary">
                            Schedule
                        </th>

                        <th class="px-lg py-md text-status-label font-semibold uppercase tracking-wide text-text-secondary">
                            Check-In
                        </th>

                        <th class="px-lg py-md text-status-label font-semibold uppercase tracking-wide text-text-secondary">
                            Check-Out
                        </th>

                        <th class="px-lg py-md text-status-label font-semibold uppercase tracking-wide text-text-secondary">
                            Status
                        </th>

                    </tr>

                </thead>


                <tbody class="divide-y divide-border">

                    {{-- Visit 1 --}}
                    <tr class="transition hover:bg-background">

                        <td class="px-lg py-md">
                            <p class="text-body text-text-primary">
                                September 27, 2026
                            </p>
                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body font-medium text-text-primary">
                                Juan Dela Cruz
                            </p>

                            <p class="text-status-label text-text-secondary">
                                PDL-00045
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body text-text-primary">
                                9:00 AM – 10:00 AM
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body text-text-primary">
                                8:52 AM
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body text-text-primary">
                                9:58 AM
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <span class="inline-flex rounded-chip bg-success/10 px-sm py-xs text-status-label font-semibold text-success">
                                Completed
                            </span>

                        </td>

                    </tr>


                    {{-- Visit 2 --}}
                    <tr class="transition hover:bg-background">

                        <td class="px-lg py-md">
                            <p class="text-body text-text-primary">
                                September 20, 2026
                            </p>
                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body font-medium text-text-primary">
                                Juan Dela Cruz
                            </p>

                            <p class="text-status-label text-text-secondary">
                                PDL-00045
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body text-text-primary">
                                1:00 PM – 2:00 PM
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body text-text-primary">
                                12:54 PM
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body text-text-primary">
                                1:57 PM
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <span class="inline-flex rounded-chip bg-success/10 px-sm py-xs text-status-label font-semibold text-success">
                                Completed
                            </span>

                        </td>

                    </tr>


                    {{-- Visit 3 --}}
                    <tr class="transition hover:bg-background">

                        <td class="px-lg py-md">
                            <p class="text-body text-text-primary">
                                September 13, 2026
                            </p>
                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body font-medium text-text-primary">
                                Juan Dela Cruz
                            </p>

                            <p class="text-status-label text-text-secondary">
                                PDL-00045
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body text-text-primary">
                                10:00 AM – 11:00 AM
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body text-text-primary">
                                9:56 AM
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body text-text-primary">
                                10:52 AM
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <span class="inline-flex rounded-chip bg-success/10 px-sm py-xs text-status-label font-semibold text-success">
                                Completed
                            </span>

                        </td>

                    </tr>


                </tbody>

            </table>

        </div>


        {{-- Table Footer --}}
        <div class="border-t border-border px-lg py-md">

            <p class="text-status-label text-text-secondary">
                Showing visitor attendance and visit history.
            </p>

        </div>

    </section>


    {{-- =========================================================
        ATTENDANCE SUMMARY
    ========================================================== --}}
    <div class="grid grid-cols-1 gap-md sm:grid-cols-3">

        {{-- Total Visits --}}
        <div class="cc-card">

            <p class="text-status-label uppercase tracking-wide text-text-secondary">
                Total Visits
            </p>

            <p class="mt-xs text-2xl font-bold text-text-primary">
                3
            </p>

            <p class="mt-xs text-status-label text-text-secondary">
                Recorded visits
            </p>

        </div>


        {{-- Completed --}}
        <div class="cc-card">

            <p class="text-status-label uppercase tracking-wide text-text-secondary">
                Completed
            </p>

            <p class="mt-xs text-2xl font-bold text-success">
                3
            </p>

            <p class="mt-xs text-status-label text-text-secondary">
                Successfully completed
            </p>

        </div>


        {{-- Attendance --}}
        <div class="cc-card">

            <p class="text-status-label uppercase tracking-wide text-text-secondary">
                Attendance
            </p>

            <p class="mt-xs text-2xl font-bold text-text-primary">
                100%
            </p>

            <p class="mt-xs text-status-label text-text-secondary">
                Visit attendance rate
            </p>

        </div>

    </div>

@endsection