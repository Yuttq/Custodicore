@extends('layouts.admin')

@section('title', 'Visit Scheduling and Approval')
@section('subtitle', 'Module 1.4 — drug-related PDLs: Thu/Sat · non-drug-related: Fri/Sun · max 2 visits/week per visitor')

@section('content')
    <div class="cc-card">
        <p class="text-card-title">Upcoming Slots</p>
        <p class="text-metadata text-text-secondary">Generated from facility_visitation_rules</p>

        <div class="mt-md overflow-x-auto">
            <table class="w-full text-left text-body">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md">Date</th>
                        <th class="py-sm pr-md">Time Slot</th>
                        <th class="py-sm pr-md">PDL Classification</th>
                        <th class="py-sm pr-md">Capacity</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($schedules as $schedule)
                        <tr>
                            <td class="py-sm pr-md font-semibold">{{ $schedule['schedule_date'] }}</td>
                            <td class="py-sm pr-md">{{ $schedule['time_slot'] }}</td>
                            <td class="py-sm pr-md">
                                <span class="cc-chip {{ $schedule['classification'] === 'drug_related' ? 'cc-chip-danger' : 'cc-chip-info' }}">
                                    {{ ucwords(str_replace('_', ' ', $schedule['classification'])) }}
                                </span>
                            </td>
                            <td class="py-sm pr-md text-text-secondary">{{ $schedule['capacity'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="cc-card">
        <p class="text-card-title">Visit Requests</p>
        <p class="text-metadata text-text-secondary">Assignments awaiting or past visitor confirmation</p>

        <div class="mt-md overflow-x-auto">
            <table class="w-full text-left text-body">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md">Reference</th>
                        <th class="py-sm pr-md">Visitor</th>
                        <th class="py-sm pr-md">PDL</th>
                        <th class="py-sm pr-md">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($visitRequests as $request)
                        <tr>
                            <td class="py-sm pr-md text-text-secondary">{{ $request['reference'] }}</td>
                            <td class="py-sm pr-md font-semibold">{{ $request['visitor'] }}</td>
                            <td class="py-sm pr-md">{{ $request['pdl'] }}</td>
                            <td class="py-sm pr-md">@include('admin.partials.status-chip', ['status' => $request['status']])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
