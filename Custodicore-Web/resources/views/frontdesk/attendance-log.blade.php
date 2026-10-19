@extends('layouts.frontdesk')

@section('title', 'Attendance Log')

@section('content')
    <section class="grid grid-cols-1 gap-md md:grid-cols-3">
        @foreach ($stats as $stat)
            @include('admin.partials.stat-card', $stat)
        @endforeach
    </section>

    <div class="cc-card">
        <p class="text-card-title">Attendance Log</p>
        <p class="text-metadata text-text-secondary">
            Every visitor check-in and check-out for {{ $date->isToday() ? 'today' : $date->format('l, F j, Y') }}. Read-only.
        </p>

        <form method="GET" action="{{ route('frontdesk.attendance-log') }}" class="mt-md flex flex-wrap gap-sm">
            <label for="attendanceDate" class="sr-only">Date</label>
            <input type="date" id="attendanceDate" name="date" value="{{ $date->format('Y-m-d') }}" max="{{ today()->format('Y-m-d') }}" class="cc-input w-auto" />

            <label for="attendanceQuery" class="sr-only">Visitor, PDL or officer</label>
            <input type="search" id="attendanceQuery" name="q" value="{{ $query }}" placeholder="Filter by visitor, PDL or officer…" maxlength="100" autocomplete="off" class="cc-input min-w-[220px] flex-1" />

            <button type="submit" class="cc-btn-primary">Show</button>
            @unless ($date->isToday() && $query === '')
                <a href="{{ route('frontdesk.attendance-log') }}" class="cc-btn-secondary">Today</a>
            @endunless
        </form>

        <div class="mt-md overflow-x-auto">
            <table class="w-full min-w-[960px] text-left">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md font-semibold">VISITOR</th>
                        <th class="py-sm pr-md font-semibold">PDL VISITED</th>
                        <th class="py-sm pr-md font-semibold">CHECK-IN</th>
                        <th class="py-sm pr-md font-semibold">CHECK-OUT</th>
                        <th class="py-sm pr-md font-semibold">HANDLED BY</th>
                        <th class="py-sm pr-md font-semibold">ID SURRENDERED</th>
                        <th class="py-sm pr-md font-semibold">METHOD</th>
                        <th class="py-sm font-semibold">STATUS</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($entries as $entry)
                        @php
                            $visitor = $entry->visitRequest?->visitor;
                            $pdl = $entry->visitRequest?->pdl;
                            $in = $entry->check_in_time;
                            $out = $entry->check_out_time;
                        @endphp
                        <tr class="text-body align-top">
                            <td class="py-sm pr-md">
                                @if ($visitor)
                                    <a href="{{ route('frontdesk.visitor-lookup.show', $visitor->visitor_id) }}" class="font-semibold text-primary-teal hover:underline">{{ $visitor->full_name }}</a>
                                    <span class="block text-metadata text-text-secondary">Visitor ID: {{ $visitor->visitor_id }}</span>
                                @else
                                    <span class="font-semibold">Unknown visitor</span>
                                @endif
                            </td>
                            <td class="py-sm pr-md">
                                {{ $pdl?->full_name ?? '—' }}
                                @if ($pdl?->pdl_number)
                                    <span class="block text-metadata text-text-secondary">{{ $pdl->pdl_number }}</span>
                                @endif
                            </td>
                            <td class="py-sm pr-md">{{ $in?->format('h:i A') ?? '—' }}</td>
                            <td class="py-sm pr-md">{{ $out?->format('h:i A') ?? '—' }}</td>
                            <td class="py-sm pr-md">
                                <span class="block"><span class="text-metadata text-text-secondary">In:</span> {{ $entry->checkInOfficer?->full_name ?? '—' }}</span>
                                <span class="block"><span class="text-metadata text-text-secondary">Out:</span> {{ $entry->checkOutOfficer?->full_name ?? ($out ? '—' : 'Not yet') }}</span>
                            </td>
                            <td class="py-sm pr-md">
                                {{ $entry->id_surrendered_type ? (new \App\Models\VisitorId(['id_type' => $entry->id_surrendered_type]))->typeLabel() : '—' }}
                                @if ($entry->id_returned_time)
                                    <span class="block text-metadata text-success">Returned {{ $entry->id_returned_time->format('h:i A') }}</span>
                                @elseif ($entry->id_surrendered_type)
                                    <span class="block text-metadata text-[#D97706]">Still held</span>
                                @endif
                            </td>
                            <td class="py-sm pr-md">{{ $entry->verification_method === 'qr_scan' ? 'QR Scan' : 'Manual' }}</td>
                            <td class="py-sm">@include('admin.partials.status-chip', ['status' => $entry->status])</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-md text-center text-metadata text-text-secondary">
                                @if ($query !== '')
                                    No gate entries matched "{{ $query }}" on this day.
                                @else
                                    No visitors checked in on this day.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($entries->total() > 0)
            <div class="mt-md flex flex-col gap-sm border-t border-border pt-md text-metadata text-text-secondary sm:flex-row sm:items-center sm:justify-between">
                <span>Showing {{ $entries->firstItem() }}–{{ $entries->lastItem() }} of {{ $entries->total() }} {{ \Illuminate\Support\Str::plural('entry', $entries->total()) }}</span>
                @if ($entries->hasPages())
                    <div class="flex items-center gap-xs">
                        @if ($entries->onFirstPage())
                            <span class="flex h-8 items-center rounded-sm border border-border px-sm text-text-secondary/40">Previous</span>
                        @else
                            <a href="{{ $entries->previousPageUrl() }}" class="flex h-8 items-center rounded-sm border border-border px-sm hover:bg-background">Previous</a>
                        @endif
                        <span class="px-sm">Page {{ $entries->currentPage() }} of {{ $entries->lastPage() }}</span>
                        @if ($entries->hasMorePages())
                            <a href="{{ $entries->nextPageUrl() }}" class="flex h-8 items-center rounded-sm border border-border px-sm hover:bg-background">Next</a>
                        @else
                            <span class="flex h-8 items-center rounded-sm border border-border px-sm text-text-secondary/40">Next</span>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </div>
@endsection
