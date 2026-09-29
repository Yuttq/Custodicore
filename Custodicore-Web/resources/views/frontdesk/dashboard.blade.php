@extends('layouts.frontdesk')

@section('title', 'Dashboard')

@section('content')
    <div class="grid grid-cols-1 gap-md sm:grid-cols-3">
        @foreach ($stats as $stat)
            @include('admin.partials.stat-card', $stat)
        @endforeach
    </div>

    <div class="cc-card">
        <p class="text-card-title">Next Arrivals Today</p>
        <p class="text-metadata text-text-secondary">Confirmed visits not yet checked in</p>

        <ul class="mt-md divide-y divide-border">
            @forelse ($upcomingArrivals as $visit)
                <li class="flex items-center gap-sm py-sm">
                    @include('admin.partials.avatar', ['name' => $visit->visitor?->full_name ?? '—', 'size' => 'h-9 w-9'])
                    <div class="flex-1">
                        <p class="text-body font-semibold">{{ $visit->visitor?->full_name ?? '—' }}</p>
                        <p class="text-metadata text-text-secondary">Visiting {{ $visit->pdl?->full_name ?? '—' }} ({{ $visit->pdl?->pdl_number }})</p>
                    </div>
                    @if ($visit->schedule)
                        <span class="text-metadata text-text-secondary">
                            {{ substr($visit->schedule->time_slot_start, 0, 5) }}–{{ substr($visit->schedule->time_slot_end, 0, 5) }}
                        </span>
                    @endif
                </li>
            @empty
                <li class="py-sm text-metadata text-text-secondary">No confirmed arrivals waiting to check in.</li>
            @endforelse
        </ul>

        <div class="mt-md">
            <a href="{{ route('frontdesk.checkin-checkout') }}" class="cc-btn-primary">Go to Check-In / Check-Out</a>
        </div>
    </div>
@endsection
