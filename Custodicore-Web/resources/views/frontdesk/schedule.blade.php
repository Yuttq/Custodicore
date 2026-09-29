@extends('layouts.frontdesk')

@section('title', "Today's Schedule")

@section('content')
    <div class="cc-card">
        <p class="text-card-title">Upcoming Slots — Next 7 Days</p>
        <p class="text-metadata text-text-secondary">Read-only · generated from facility_visitation_rules</p>

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
                        <tr class="{{ $schedule['is_today'] ? 'bg-info/5' : '' }}">
                            <td class="py-sm pr-md font-semibold">
                                {{ $schedule['schedule_date'] }}
                                @if ($schedule['is_today'])
                                    <span class="cc-chip cc-chip-info ml-xs">Today</span>
                                @endif
                            </td>
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
@endsection
