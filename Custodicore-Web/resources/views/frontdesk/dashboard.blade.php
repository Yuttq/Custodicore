@extends('layouts.frontdesk')

@section('title', 'Dashboard')

@section('content')
    @if ($unresolvedInside > 0)
        <div class="cc-card border-l-4 border-danger" role="alert">
            <p class="text-card-title text-danger">
                {{ $unresolvedInside }} {{ \Illuminate\Support\Str::plural('visitor', $unresolvedInside) }} still marked inside from a previous day
            </p>
            <p class="text-metadata text-text-secondary">
                Their check-out was probably never recorded. Confirm they have left, then use
                "Close record – exit not recorded" so no false exit time is saved.
            </p>
            <a href="{{ route('frontdesk.schedule', 'inside') }}" class="mt-xs inline-block font-semibold text-primary-teal hover:underline">Review visitors still marked inside</a>
        </div>
    @endif

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
                    @if ($visit->sessionSchedules->isNotEmpty())
                        {{-- A whole-day visit lists both sessions. --}}
                        <span class="text-metadata text-text-secondary">
                            {{ $visit->sessionSchedules->map(fn ($s) => substr($s->time_slot_start, 0, 5).'–'.substr($s->time_slot_end, 0, 5))->implode(' · ') }}
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

