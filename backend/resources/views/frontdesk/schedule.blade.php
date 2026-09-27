@extends('layouts.frontdesk')

@section('title', "Today's Schedule")

@section('content')

    {{-- =========================================================
        PAGE HEADER
    ========================================================== --}}
    <div class="flex flex-col gap-sm sm:flex-row sm:items-center sm:justify-between">

        <div>
            <p class="text-body text-text-secondary">
                View and manage today's approved visitor schedules.
            </p>
        </div>

        <div class="rounded-chip border border-border bg-card px-md py-sm">
            <p class="text-status-label uppercase tracking-wide text-text-secondary">
                Today
            </p>

            <p class="text-body font-semibold text-text-primary">
                {{ date('F d, Y') }}
            </p>
        </div>

    </div>


    {{-- =========================================================
        SUMMARY CARDS
    ========================================================== --}}
    <div class="grid grid-cols-1 gap-md sm:grid-cols-2 xl:grid-cols-4">

        {{-- Approved Visits --}}
        <div class="cc-card">
            <p class="text-status-label uppercase tracking-wide text-text-secondary">
                Approved Visits
            </p>

            <p class="mt-xs text-2xl font-bold text-text-primary">
                12
            </p>

            <p class="mt-xs text-status-label text-text-secondary">
                Scheduled for today
            </p>
        </div>


        {{-- Upcoming --}}
        <div class="cc-card">
            <p class="text-status-label uppercase tracking-wide text-text-secondary">
                Upcoming
            </p>

            <p class="mt-xs text-2xl font-bold text-text-primary">
                7
            </p>

            <p class="mt-xs text-status-label text-text-secondary">
                Visitors not yet checked in
            </p>
        </div>


        {{-- Checked In --}}
        <div class="cc-card">
            <p class="text-status-label uppercase tracking-wide text-text-secondary">
                Checked In
            </p>

            <p class="mt-xs text-2xl font-bold text-text-primary">
                3
            </p>

            <p class="mt-xs text-status-label text-text-secondary">
                Currently inside
            </p>
        </div>


        {{-- Completed --}}
        <div class="cc-card">
            <p class="text-status-label uppercase tracking-wide text-text-secondary">
                Completed
            </p>

            <p class="mt-xs text-2xl font-bold text-text-primary">
                2
            </p>

            <p class="mt-xs text-status-label text-text-secondary">
                Visits completed
            </p>
        </div>

    </div>


    {{-- =========================================================
        TODAY'S SCHEDULE TABLE
    ========================================================== --}}
    <section class="cc-card overflow-hidden p-0">

        {{-- Table Header --}}
        <div class="flex flex-col gap-sm border-b border-border px-lg py-md sm:flex-row sm:items-center sm:justify-between">

            <div>
                <h2 class="text-card-title font-semibold text-text-primary">
                    Today's Schedule
                </h2>

                <p class="mt-xs text-status-label text-text-secondary">
                    Approved visits scheduled for today
                </p>
            </div>

        </div>


        {{-- Table --}}
        <div class="overflow-x-auto">

            <table class="w-full text-left">

                <thead class="border-b border-border bg-background">

                    <tr>

                        <th class="px-lg py-md text-status-label font-semibold uppercase tracking-wide text-text-secondary">
                            Visitor
                        </th>

                        <th class="px-lg py-md text-status-label font-semibold uppercase tracking-wide text-text-secondary">
                            PDL
                        </th>

                        <th class="px-lg py-md text-status-label font-semibold uppercase tracking-wide text-text-secondary">
                            Schedule
                        </th>

                        <th class="px-lg py-md text-status-label font-semibold uppercase tracking-wide text-text-secondary">
                            Visit Type
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

                            <p class="text-body font-semibold text-text-primary">
                                Maria Santos
                            </p>

                            <p class="text-status-label text-text-secondary">
                                VIS-00124
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body text-text-primary">
                                Juan Dela Cruz
                            </p>

                            <p class="text-status-label text-text-secondary">
                                PDL-00045
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body font-medium text-text-primary">
                                9:00 AM – 10:00 AM
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <span class="text-body text-text-secondary">
                                Regular Visit
                            </span>

                        </td>


                        <td class="px-lg py-md">

                            <span class="inline-flex rounded-chip bg-success/10 px-sm py-xs text-status-label font-semibold text-success">
                                Approved
                            </span>

                        </td>

                    </tr>


                    {{-- Visit 2 --}}
                    <tr class="transition hover:bg-background">

                        <td class="px-lg py-md">

                            <p class="text-body font-semibold text-text-primary">
                                Ana Reyes
                            </p>

                            <p class="text-status-label text-text-secondary">
                                VIS-00125
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body text-text-primary">
                                Pedro Garcia
                            </p>

                            <p class="text-status-label text-text-secondary">
                                PDL-00031
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body font-medium text-text-primary">
                                10:30 AM – 11:30 AM
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <span class="text-body text-text-secondary">
                                Regular Visit
                            </span>

                        </td>


                        <td class="px-lg py-md">

                            <span class="inline-flex rounded-chip bg-warning/10 px-sm py-xs text-status-label font-semibold text-warning">
                                Upcoming
                            </span>

                        </td>

                    </tr>


                    {{-- Visit 3 --}}
                    <tr class="transition hover:bg-background">

                        <td class="px-lg py-md">

                            <p class="text-body font-semibold text-text-primary">
                                Carlos Mendoza
                            </p>

                            <p class="text-status-label text-text-secondary">
                                VIS-00126
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body text-text-primary">
                                Roberto Aquino
                            </p>

                            <p class="text-status-label text-text-secondary">
                                PDL-00018
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body font-medium text-text-primary">
                                1:00 PM – 2:00 PM
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <span class="text-body text-text-secondary">
                                Regular Visit
                            </span>

                        </td>


                        <td class="px-lg py-md">

                            <span class="inline-flex rounded-chip bg-info/10 px-sm py-xs text-status-label font-semibold text-info">
                                Scheduled
                            </span>

                        </td>

                    </tr>


                    {{-- Visit 4 --}}
                    <tr class="transition hover:bg-background">

                        <td class="px-lg py-md">

                            <p class="text-body font-semibold text-text-primary">
                                Elena Garcia
                            </p>

                            <p class="text-status-label text-text-secondary">
                                VIS-00127
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body text-text-primary">
                                Michael Torres
                            </p>

                            <p class="text-status-label text-text-secondary">
                                PDL-00052
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body font-medium text-text-primary">
                                2:30 PM – 3:30 PM
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <span class="text-body text-text-secondary">
                                Special Visit
                            </span>

                        </td>


                        <td class="px-lg py-md">

                            <span class="inline-flex rounded-chip bg-info/10 px-sm py-xs text-status-label font-semibold text-info">
                                Scheduled
                            </span>

                        </td>

                    </tr>


                    {{-- Visit 5 --}}
                    <tr class="transition hover:bg-background">

                        <td class="px-lg py-md">

                            <p class="text-body font-semibold text-text-primary">
                                Sofia Ramos
                            </p>

                            <p class="text-status-label text-text-secondary">
                                VIS-00128
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body text-text-primary">
                                Daniel Flores
                            </p>

                            <p class="text-status-label text-text-secondary">
                                PDL-00063
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <p class="text-body font-medium text-text-primary">
                                4:00 PM – 5:00 PM
                            </p>

                        </td>


                        <td class="px-lg py-md">

                            <span class="text-body text-text-secondary">
                                Regular Visit
                            </span>

                        </td>


                        <td class="px-lg py-md">

                            <span class="inline-flex rounded-chip bg-info/10 px-sm py-xs text-status-label font-semibold text-info">
                                Scheduled
                            </span>

                        </td>

                    </tr>

                </tbody>

            </table>

        </div>


        {{-- Table Footer --}}
        <div class="border-t border-border px-lg py-md">

            <p class="text-status-label text-text-secondary">
                Showing today's approved visitor schedules.
            </p>

        </div>

    </section>

@endsection