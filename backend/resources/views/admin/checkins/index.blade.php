@extends('layouts.admin')

@section('title', 'QR Check-In and Check-Out')
@section('subtitle', "Module 1.5 — today's arrivals, ID surrender and QR scan monitoring")

@section('content')
    <div class="cc-card">
        <p class="text-card-title">Today's Visits</p>

        <div class="mt-md overflow-x-auto">
            <table class="w-full text-left text-body">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md">Reference</th>
                        <th class="py-sm pr-md">Visitor</th>
                        <th class="py-sm pr-md">ID Surrendered</th>
                        <th class="py-sm pr-md">Check-In</th>
                        <th class="py-sm pr-md">Check-Out</th>
                        <th class="py-sm pr-md">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($checkins as $checkin)
                        <tr>
                            <td class="py-sm pr-md text-text-secondary">{{ $checkin['reference'] }}</td>
                            <td class="py-sm pr-md font-semibold">{{ $checkin['visitor'] }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ $checkin['id_surrendered'] ?? '—' }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ $checkin['check_in_time'] ?? '—' }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ $checkin['check_out_time'] ?? '—' }}</td>
                            <td class="py-sm pr-md">@include('admin.partials.status-chip', ['status' => $checkin['status']])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
