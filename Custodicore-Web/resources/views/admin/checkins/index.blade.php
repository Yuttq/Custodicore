@extends('layouts.admin')

@section('title', 'Check-In / Check-Out')

@section('content')
    <div class="grid grid-cols-1 gap-md sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($summary as $stat)
            @include('admin.partials.stat-card', $stat)
        @endforeach
    </div>

    <div class="cc-card">
        <div class="mb-md flex flex-col gap-sm lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="text-card-title">Visitors Who Entered Today</p>
                <p class="text-metadata text-text-secondary">Checked in and out via QR code at the gate · read-only</p>
            </div>
            @include('admin.partials.search-box', ['placeholder' => 'Search by reference or visitor…'])
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-body">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md">Reference</th>
                        <th class="py-sm pr-md">Visitor</th>
                        <th class="py-sm pr-md">Check-In</th>
                        <th class="py-sm pr-md">Check-Out</th>
                        <th class="py-sm pr-md">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($checkins as $checkin)
                        <tr class="transition hover:bg-background">
                            <td class="py-sm pr-md text-text-secondary">{{ $checkin['reference'] }}</td>
                            <td class="py-sm pr-md">
                                <div class="flex items-center gap-sm">
                                    @include('admin.partials.avatar', ['name' => $checkin['visitor'], 'size' => 'h-8 w-8'])
                                    <span class="font-semibold">{{ $checkin['visitor'] }}</span>
                                </div>
                            </td>
                            <td class="py-sm pr-md text-text-secondary">{{ $checkin['check_in_time'] }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ $checkin['check_out_time'] ?? 'Still inside' }}</td>
                            <td class="py-sm pr-md">@include('admin.partials.status-chip', ['status' => $checkin['status']])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @include('admin.partials.pagination-footer', ['total' => count($checkins), 'label' => 'visits'])
    </div>
@endsection
