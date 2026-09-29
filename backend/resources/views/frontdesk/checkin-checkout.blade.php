@extends('layouts.frontdesk')

@section('title', 'Check-In / Check-Out')

@section('content')
    <div class="grid grid-cols-1 gap-lg xl:grid-cols-2">
        <div class="cc-card">
            <p class="text-card-title">Expected Today — Not Yet Checked In</p>
            <p class="text-metadata text-text-secondary">Confirmed visit requests for today's schedule</p>

            <ul class="mt-md divide-y divide-border">
                @forelse ($expected as $visit)
                    <li class="flex items-center gap-sm py-sm">
                        @include('admin.partials.avatar', ['name' => $visit->visitor?->full_name ?? '—', 'size' => 'h-9 w-9'])
                        <div class="flex-1">
                            <p class="text-body font-semibold">{{ $visit->visitor?->full_name ?? '—' }}</p>
                            <p class="text-metadata text-text-secondary">Visiting {{ $visit->pdl?->full_name ?? '—' }} ({{ $visit->pdl?->pdl_number }})</p>
                        </div>
                        <form method="POST" action="{{ route('frontdesk.checkin-checkout.check-in', $visit->visit_request_id) }}">
                            @csrf
                            <button type="submit" class="cc-btn-primary">Check In</button>
                        </form>
                    </li>
                @empty
                    <li class="py-sm text-metadata text-text-secondary">Nobody waiting to check in right now.</li>
                @endforelse
            </ul>
        </div>

        <div class="cc-card">
            <p class="text-card-title">Currently Inside</p>
            <p class="text-metadata text-text-secondary">Checked in, not yet checked out</p>

            <ul class="mt-md divide-y divide-border">
                @forelse ($insideNow as $checkin)
                    <li class="flex items-center gap-sm py-sm">
                        @include('admin.partials.avatar', ['name' => $checkin->visitRequest?->visitor?->full_name ?? '—', 'size' => 'h-9 w-9'])
                        <div class="flex-1">
                            <p class="text-body font-semibold">{{ $checkin->visitRequest?->visitor?->full_name ?? '—' }}</p>
                            <p class="text-metadata text-text-secondary">In since {{ $checkin->check_in_time?->format('h:i A') }}</p>
                        </div>
                        <form method="POST" action="{{ route('frontdesk.checkin-checkout.check-out', $checkin->checkin_id) }}"
                              data-confirm="Check out {{ $checkin->visitRequest?->visitor?->full_name ?? 'this visitor' }} and complete the visit?">
                            @csrf
                            <button type="submit" class="cc-btn-secondary">Check Out</button>
                        </form>
                    </li>
                @empty
                    <li class="py-sm text-metadata text-text-secondary">Nobody currently inside.</li>
                @endforelse
            </ul>
        </div>
    </div>

    <div class="cc-card">
        <p class="text-card-title">Today's Gate Log</p>

        <div class="mt-md overflow-x-auto">
            <table class="w-full text-left text-body">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md">Visitor</th>
                        <th class="py-sm pr-md">Check-In</th>
                        <th class="py-sm pr-md">Check-Out</th>
                        <th class="py-sm pr-md">Method</th>
                        <th class="py-sm pr-md">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($todayHistory as $checkin)
                        <tr>
                            <td class="py-sm pr-md font-semibold">{{ $checkin->visitRequest?->visitor?->full_name ?? '—' }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ $checkin->check_in_time?->format('h:i A') }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ $checkin->check_out_time?->format('h:i A') ?? 'Still inside' }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ ucwords(str_replace('_', ' ', $checkin->verification_method)) }}</td>
                            <td class="py-sm pr-md">@include('admin.partials.status-chip', ['status' => $checkin->status])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
