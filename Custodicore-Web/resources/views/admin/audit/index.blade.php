@extends('layouts.admin')

@section('title', 'Audit Trail')

@section('content')
    <div class="grid grid-cols-1 gap-md sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($summary as $stat)
            @include('admin.partials.stat-card', $stat)
        @endforeach
    </div>

    <div class="cc-card">
        <div class="mb-md flex flex-col gap-sm lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="text-card-title">Recent Activity</p>
                <p class="text-metadata text-text-secondary">
                    @if ($filtered)
                        {{ $auditLogs->total() }} of {{ $totalLogs }} entries match
                        @if ($search) "{{ $search }}" @endif
                        @if ($from || $to)
                            · {{ $from ? \Carbon\Carbon::parse($from)->format('M j, Y') : 'start' }} – {{ $to ? \Carbon\Carbon::parse($to)->format('M j, Y') : 'today' }}
                        @endif
                        @if ($fromTime || $toTime)
                            · {{ $fromTime ?? '00:00' }}–{{ $toTime ?? '23:59' }}
                        @endif
                    @else
                        {{ $totalLogs }} entries, newest first
                    @endif
                </p>
            </div>
            {{-- Search (name, action, module or description) + date range + time of day (24-hour). --}}
            <form method="GET" action="{{ route('admin.audit.index') }}" class="flex flex-wrap items-end gap-sm">
                <label class="flex flex-col text-metadata text-text-secondary">
                    Search
                    <input type="search" name="q" value="{{ $search }}" maxlength="100"
                           placeholder="Name, action, module or description…"
                           class="h-[38px] w-full rounded-button border border-border bg-white px-md text-body text-text-primary sm:w-64" />
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
                <label class="flex flex-col text-metadata text-text-secondary">
                    From time (24h)
                    <input type="text" name="from_time" value="{{ $fromTime }}" placeholder="HH:MM" maxlength="5"
                           inputmode="numeric" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" title="24-hour time, e.g. 08:30"
                           class="h-[38px] w-24 rounded-button border border-border bg-white px-sm text-body text-text-primary" />
                </label>
                <label class="flex flex-col text-metadata text-text-secondary">
                    To time (24h)
                    <input type="text" name="to_time" value="{{ $toTime }}" placeholder="HH:MM" maxlength="5"
                           inputmode="numeric" pattern="([01][0-9]|2[0-3]):[0-5][0-9]" title="24-hour time, e.g. 17:00"
                           class="h-[38px] w-24 rounded-button border border-border bg-white px-sm text-body text-text-primary" />
                </label>
                <button type="submit" class="cc-btn-secondary shrink-0">Filter</button>
                @if ($filtered)
                    <a href="{{ route('admin.audit.index') }}" class="cc-btn-secondary shrink-0">Clear</a>
                @endif
            </form>
        </div>
        <div class="mb-md flex flex-wrap items-center gap-sm text-metadata">
            <span class="text-text-secondary">Quick range:</span>
            <a href="{{ route('admin.audit.index', array_filter(['q' => $search, 'from' => now()->toDateString(), 'to' => now()->toDateString(), 'from_time' => $fromTime, 'to_time' => $toTime])) }}" class="cc-chip">Today</a>
            <a href="{{ route('admin.audit.index', array_filter(['q' => $search, 'from' => now()->subDays(6)->toDateString(), 'to' => now()->toDateString(), 'from_time' => $fromTime, 'to_time' => $toTime])) }}" class="cc-chip">Last 7 days</a>
            <a href="{{ route('admin.audit.index', array_filter(['q' => $search, 'from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString(), 'from_time' => $fromTime, 'to_time' => $toTime])) }}" class="cc-chip">This month</a>
            <span class="ml-sm text-text-secondary">Time of day:</span>
            @foreach ([['Morning', '06:00', '11:59'], ['Afternoon', '12:00', '17:59'], ['Evening', '18:00', '23:59'], ['Night', '00:00', '05:59']] as [$period, $pf, $pt])
                <a href="{{ route('admin.audit.index', array_filter(['q' => $search, 'from' => $from, 'to' => $to, 'from_time' => $pf, 'to_time' => $pt])) }}"
                   class="cc-chip {{ $fromTime === $pf && $toTime === $pt ? 'cc-chip-info' : '' }}">{{ $period }} {{ $pf }}–{{ $pt }}</a>
            @endforeach
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-body">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md">When</th>
                        <th class="py-sm pr-md">Account</th>
                        <th class="py-sm pr-md">Action</th>
                        <th class="py-sm pr-md">Module</th>
                        <th class="py-sm pr-md">Description</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($auditLogs as $log)
                        <tr class="transition hover:bg-background">
                            <td class="py-sm pr-md text-text-secondary">{{ $log['created_at'] }}</td>
                            <td class="py-sm pr-md">
                                <div class="flex items-center gap-sm">
                                    @include('admin.partials.avatar', [
                                        'name' => $log['is_system'] ? 'SY' : $log['account'],
                                        'size' => 'h-8 w-8',
                                        'color' => $log['is_system'] ? 'bg-text-secondary' : null,
                                    ])
                                    <span>{{ $log['account'] }}</span>
                                </div>
                            </td>
                            <td class="py-sm pr-md">
                                <span class="cc-chip cc-chip-neutral">{{ ucwords(str_replace('_', ' ', $log['action_type'])) }}</span>
                            </td>
                            <td class="py-sm pr-md text-text-secondary">{{ $log['module'] }}</td>
                            <td class="py-sm pr-md">{{ $log['description'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-lg text-center text-text-secondary">No entries match your search.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('admin.partials.pagination-footer', ['paginator' => $auditLogs, 'label' => 'entries'])
    </div>
@endsection
