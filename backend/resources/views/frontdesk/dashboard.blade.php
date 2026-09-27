@extends('layouts.frontdesk')

@section('title', 'Dashboard')

@section('content')

    <div>
        <p class="text-body text-text-secondary">
            Welcome to the CustodiCore Front Desk Dashboard.
        </p>
    </div>

    <div class="grid grid-cols-1 gap-md sm:grid-cols-2 xl:grid-cols-4">

        <div class="cc-card">
            <p class="text-status-label uppercase tracking-wide text-text-secondary">
                Today's Visits
            </p>

            <p class="mt-xs text-2xl font-bold text-text-primary">
                12
            </p>

            <p class="mt-xs text-status-label text-text-secondary">
                Scheduled today
            </p>
        </div>

        <div class="cc-card">
            <p class="text-status-label uppercase tracking-wide text-text-secondary">
                Currently Checked-In
            </p>

            <p class="mt-xs text-2xl font-bold text-text-primary">
                3
            </p>

            <p class="mt-xs text-status-label text-text-secondary">
                Visitors currently inside
            </p>
        </div>

        <div class="cc-card">
            <p class="text-status-label uppercase tracking-wide text-text-secondary">
                Upcoming
            </p>

            <p class="mt-xs text-2xl font-bold text-text-primary">
                7
            </p>

            <p class="mt-xs text-status-label text-text-secondary">
                Visitors expected
            </p>
        </div>

        <div class="cc-card">
            <p class="text-status-label uppercase tracking-wide text-text-secondary">
                Completed
            </p>

            <p class="mt-xs text-2xl font-bold text-text-primary">
                2
            </p>

            <p class="mt-xs text-status-label text-text-secondary">
                Visits completed today
            </p>
        </div>

    </div>

@endsection