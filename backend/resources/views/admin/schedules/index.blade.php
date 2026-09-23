@extends('layouts.admin')

@section('title', 'Visit Scheduling and Approval')

@section('content')
    <div class="grid grid-cols-1 gap-md sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($summary as $stat)
            @include('admin.partials.stat-card', $stat)
        @endforeach
    </div>

    <div class="cc-card">
        <p class="text-card-title">Upcoming Slots</p>
        <p class="text-metadata text-text-secondary">Generated from facility_visitation_rules · read-only</p>

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
                        <tr class="transition hover:bg-background">
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

        @include('admin.partials.pagination-footer', ['total' => count($schedules), 'label' => 'slots'])
    </div>
@endsection
