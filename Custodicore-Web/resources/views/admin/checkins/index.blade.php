@extends('layouts.admin')

@section('title', 'Check-In / Check-Out')

@section('content')
    <div class="grid grid-cols-1 gap-md sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($summary as $stat)
            @include('admin.partials.stat-card', $stat)
        @endforeach
    </div>

    <div class="cc-card">
        <div class="mb-md flex flex-col gap-sm xl:flex-row xl:items-end xl:justify-between">
            <div>
                <p class="text-card-title">Visitors Who Entered</p>
                <p class="text-metadata text-text-secondary">
                    Checked in and out via QR code at the gate · times in 24-hour format
                    @if ($filtered)
                        · {{ $checkins->total() }} {{ $checkins->total() === 1 ? 'record matches' : 'records match' }}
                    @endif
                </p>
            </div>

            {{-- Search + date range. "Export PDF" downloads every row matching these filters. --}}
            <form method="GET" action="{{ route('admin.checkins.index') }}" class="flex flex-wrap items-end gap-sm">
                <label class="flex flex-col text-metadata text-text-secondary">
                    Search
                    <input type="search" name="q" value="{{ $search }}" maxlength="100"
                           placeholder="Reference or visitor…"
                           class="h-[38px] w-full rounded-button border border-border bg-white px-md text-body text-text-primary sm:w-56" />
                </label>
                <label class="flex flex-col text-metadata text-text-secondary">
                    From
                    <input type="date" name="from" value="{{ $from }}" max="{{ now()->toDateString() }}"
                           class="h-[38px] rounded-button border border-border bg-white px-sm text-body text-text-primary" />
                </label>
                <label class="flex flex-col text-metadata text-text-secondary">
                    To
                    <input type="date" name="to" value="{{ $to }}" max="{{ now()->toDateString() }}"
                           class="h-[38px] rounded-button border border-border bg-white px-sm text-body text-text-primary" />
                </label>
                <button type="submit" class="cc-btn-secondary shrink-0">Filter</button>
                @if ($filtered)
                    <a href="{{ route('admin.checkins.index') }}" class="cc-btn-secondary shrink-0">Clear</a>
                @endif
                <a href="{{ route('admin.checkins.export', array_filter(['q' => $search, 'from' => $from, 'to' => $to])) }}"
                   class="cc-btn-primary shrink-0">Export PDF</a>
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-body">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md">Date</th>
                        <th class="py-sm pr-md">Reference</th>
                        <th class="py-sm pr-md">Visitor</th>
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
                            <td class="py-sm pr-md text-text-secondary">{{ $checkin['check_in_time'] }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ $checkin['check_out_time'] ?? 'Still inside' }}</td>
                            <td class="py-sm pr-md">@include('admin.partials.status-chip', ['status' => $checkin['status']])</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-lg text-center text-text-secondary">
                                {{ $filtered ? 'No check-ins match your search.' : 'No check-ins recorded yet.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('admin.partials.pagination-footer', ['paginator' => $checkins, 'label' => 'visits'])
    </div>
@endsection
