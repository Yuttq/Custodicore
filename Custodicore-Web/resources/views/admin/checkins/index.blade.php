@extends('layouts.admin')

@section('title', 'Check-In / Check-Out')

@section('content')
    <div class="grid grid-cols-1 gap-md sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($summary as $stat)
            @include('admin.partials.stat-card', $stat)
        @endforeach
    </div>

    <div class="cc-card">
        <div class="mb-md flex flex-col gap-sm lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-card-title">Visitors Who Entered</p>
                <p class="text-metadata text-text-secondary">
                    Checked in and out via QR code at the gate · times in 24-hour format
                    @if ($filter)
                        · <span class="font-semibold text-text-primary">Currently inside only</span>
                        · <a href="{{ route('admin.checkins.index', array_filter(['q' => $search])) }}" class="font-semibold text-primary-navy hover:underline">Show all</a>
                    @endif
                    @if ($filtered)
                        · {{ $checkins->total() }} {{ $checkins->total() === 1 ? 'record matches' : 'records match' }}
                        @if ($dateLabel) <span class="font-semibold text-text-primary">{{ $dateLabel }}</span>@else "{{ $search }}"@endif
                    @endif
                </p>
            </div>

            {{-- One box: a date, month, year or time lists every visit in that period; anything
                 else searches the reference or visitor name. "Export PDF" uses the same search. --}}
            <form method="GET" action="{{ route('admin.checkins.index') }}" class="w-full lg:w-[32rem]">
                <div class="flex items-center gap-sm">
                    @if ($filter)<input type="hidden" name="filter" value="{{ $filter }}" />@endif
                    <input type="search" name="q" value="{{ $search }}" maxlength="100"
                           placeholder="Search a date, month, year, visitor or PDL…" autocomplete="off"
                           class="h-[38px] w-full rounded-button border border-border bg-white px-md text-body text-text-primary" />
                    <button type="submit" class="cc-btn-secondary shrink-0">Search</button>
                    @if ($filtered)
                        <a href="{{ route('admin.checkins.index', array_filter(['filter' => $filter])) }}" class="cc-btn-secondary shrink-0">Clear</a>
                    @endif
                    <a href="{{ route('admin.checkins.export', array_filter(['q' => $search, 'filter' => $filter])) }}"
                       class="cc-btn-primary shrink-0">Export PDF</a>
                </div>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-body">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md">Date</th>
                        <th class="py-sm pr-md">Reference</th>
                        <th class="py-sm pr-md">Visitor</th>
                        <th class="py-sm pr-md">PDL</th>
                        <th class="py-sm pr-md">Purpose</th>
                        <th class="py-sm pr-md">Check-In</th>
                        <th class="py-sm pr-md">Check-Out</th>
                        <th class="py-sm pr-md">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($checkins as $checkin)
                        <tr class="transition hover:bg-background">
                            <td class="py-sm pr-md text-text-secondary">{{ $checkin['date'] }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ $checkin['reference'] }}</td>
                            <td class="py-sm pr-md">
                                <div class="flex items-center gap-sm">
                                    @include('admin.partials.avatar', ['name' => $checkin['visitor'], 'size' => 'h-8 w-8'])
                                    <span class="font-semibold">{{ $checkin['visitor'] }}</span>
                                </div>
                            </td>
                            <td class="py-sm pr-md">{{ $checkin['pdl'] }}</td>
                            <td class="py-sm pr-md">{{ $checkin['purpose'] }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ $checkin['check_in_time'] }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ $checkin['check_out_time'] ?? 'Still inside' }}</td>
                            <td class="py-sm pr-md">@include('admin.partials.status-chip', ['status' => $checkin['status']])</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-lg text-center text-text-secondary">
                                {{ $filter ? 'Nobody is currently inside.' : ($dateLabel ? 'No check-ins were recorded for ' . $dateLabel . '.' : ($filtered ? 'No check-ins match your search.' : 'No check-ins recorded yet.')) }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('admin.partials.pagination-footer', ['paginator' => $checkins, 'label' => 'visits'])
    </div>
@endsection
