@extends('layouts.frontdesk')

@section('title', 'Activity Log')

@section('content')
    @php
        $C = \App\Http\Controllers\FrontDesk\AttendanceLogController::class;
        $period = $date->isToday() ? 'today' : $date->format('l, F j, Y');
        $dateParam = $date->isToday() ? null : $date->format('Y-m-d');
        // Tab links keep the shared day and search; each tab's own filter stays behind.
        $tabLink = fn ($key) => route('frontdesk.attendance-log', array_filter(['tab' => $key === 'gate' ? null : $key, 'date' => $dateParam, 'q' => $query]));
        $dayLink = fn ($d) => route('frontdesk.attendance-log', array_filter([
            'tab' => $tab === 'gate' ? null : $tab,
            'date' => $d->isToday() ? null : $d->format('Y-m-d'),
            'q' => $query, 'method' => $method, 'type' => $type,
        ]));
    @endphp

    <div class="cc-card">
        <div class="flex flex-wrap items-center justify-between gap-md">
            <nav class="flex flex-wrap gap-xs" aria-label="Activity log">
                @foreach ($C::TABS as $key => $label)
                    <a href="{{ $tabLink($key) }}"
                       class="{{ $key === $tab ? 'cc-btn-primary' : 'cc-btn-secondary' }} gap-xs"
                       @if ($key === $tab) aria-current="page" @endif>
                        {{ $label }}
                        <span class="cc-chip {{ $key === $tab ? 'bg-white/20 text-white' : 'cc-chip-info' }}">{{ $counts[$key] }}</span>
                    </a>
                @endforeach
            </nav>

            <form method="GET" action="{{ route('frontdesk.attendance-log') }}" class="flex flex-wrap items-center justify-end gap-sm">
                @if ($tab !== 'gate')
                    <input type="hidden" name="tab" value="{{ $tab }}">
                @endif

                @include('frontdesk.partials.search-input', [
                    'id' => 'attendanceQuery',
                    'value' => $query,
                    'placeholder' => $tab === 'gate' ? 'Search visitor, PDL or officer…' : 'Search activity or officer…',
                ])

                @if ($tab === 'gate')
                    <label for="attendanceMethod" class="sr-only">Method</label>
                    <select id="attendanceMethod" name="method" onchange="this.form.submit()" class="cc-input !mt-0 w-auto">
                        <option value="">All methods</option>
                        @foreach ($C::METHODS as $key => $label)
                            <option value="{{ $key }}" @selected($method === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                @else
                    <label for="activityType" class="sr-only">Type</label>
                    <select id="activityType" name="type" onchange="this.form.submit()" class="cc-input !mt-0 w-auto">
                        <option value="">All types</option>
                        @foreach ($C::ACTIVITY_TYPES as $key => $label)
                            <option value="{{ $key }}" @selected($type === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                @endif

                @include('frontdesk.partials.date-nav', [
                    'value' => $date->format('Y-m-d'),
                    'prevUrl' => $dayLink($date->copy()->subDay()),
                    'nextUrl' => $date->isToday() ? null : $dayLink($date->copy()->addDay()),
                    'max' => today(),
                ])

                {{-- Enter in the search box also submits. --}}
                <button type="submit" class="sr-only">Search</button>
                @if ($filtered)
                    <a href="{{ $tab === 'gate' ? route('frontdesk.attendance-log') : route('frontdesk.attendance-log', ['tab' => $tab]) }}" class="cc-btn-secondary">Clear</a>
                @endif
            </form>
        </div>

        <p class="mt-md text-metadata text-text-secondary">
            @if ($tab === 'gate')
                One row per visit {{ $date->isToday() ? 'today' : 'on '.$period }}: check-in and check-out, who handled it, and whether the ID is still held.
            @else
                Everything recorded {{ $date->isToday() ? 'today' : 'on '.$period }}: walk-in registrations and decisions, bookings,
                check-ins and check-outs, and ID updates{{ $query !== '' ? ', matching "'.$query.'"' : '' }}. Newest first.
            @endif
        </p>

        @if ($tab === 'gate')
            <div class="mt-lg overflow-x-auto border-t border-border pt-md">
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
                        @forelse ($list as $entry)
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
                                <td class="py-sm pr-md">{{ $in?->format('H:i') ?? '—' }}</td>
                                <td class="py-sm pr-md">{{ $out?->format('H:i') ?? '—' }}</td>
                                <td class="py-sm pr-md">
                                    <span class="block"><span class="text-metadata text-text-secondary">In:</span> {{ $entry->checkInOfficer?->full_name ?? '—' }}</span>
                                    <span class="block"><span class="text-metadata text-text-secondary">Out:</span> {{ $entry->checkOutOfficer?->full_name ?? ($out ? '—' : ($entry->isExitUnrecorded() ? 'Not recorded' : 'Not yet')) }}</span>
                                </td>
                                <td class="py-sm pr-md">
                                    {{ $entry->id_surrendered_type ? (new \App\Models\VisitorId(['id_type' => $entry->id_surrendered_type]))->typeLabel() : '—' }}
                                    @if ($entry->id_returned_time)
                                        <span class="block text-metadata text-success">Returned {{ $entry->id_returned_time->format('H:i') }}</span>
                                    @elseif ($entry->id_surrendered_type && $entry->isExitUnrecorded())
                                        {{-- Closed without a check-out: the ID's whereabouts are in the audit log. --}}
                                        <span class="block text-metadata text-text-secondary">Return not recorded</span>
                                    @elseif ($entry->id_surrendered_type)
                                        <span class="block text-metadata text-[#D97706]">Still held</span>
                                    @endif
                                </td>
                                <td class="py-sm pr-md">{{ $entry->verification_method === 'qr_scan' ? 'QR Scan' : 'Manual' }}</td>
                                <td class="py-sm">
                                    @if ($entry->status === 'checked_in' && ! $date->isToday())
                                        {{-- An earlier day with no check-out recorded. --}}
                                        <span class="font-semibold text-danger">Never checked out</span>
                                @else
                                        @include('admin.partials.status-chip', ['status' => $entry->status])
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-md text-center text-metadata text-text-secondary">
                                    @if ($query !== '')
                                        No gate entries matched "{{ $query }}" for {{ $period }}.
                                    @elseif ($method !== '')
                                        No {{ $C::METHODS[$method] }} entries for {{ $period }}.
                                @else
                                        No visitors checked in on this day.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @include('frontdesk.partials.pagination', ['paginator' => $list, 'noun' => 'entry'])
        @else
            <div class="mt-lg overflow-x-auto border-t border-border pt-md">
                <table class="w-full min-w-[760px] text-left">
                    <thead>
                        <tr class="border-b border-border text-section-label text-text-secondary">
                            <th class="py-sm pr-md font-semibold">TIME</th>
                            <th class="py-sm pr-md font-semibold">TYPE</th>
                            <th class="py-sm pr-md font-semibold">ACTIVITY</th>
                            <th class="py-sm font-semibold">DONE BY</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($list as $log)
                            @php $tone = \App\Services\ActivityLog::tone($log); @endphp
                            <tr class="text-body align-top">
                                <td class="whitespace-nowrap py-sm pr-md">{{ $log->created_at?->format('H:i') }}</td>
                                <td class="py-sm pr-md">
                                    <span @class([
                                        'cc-chip whitespace-nowrap',
                                        'cc-chip-success' => $tone === 'ok',
                                        'cc-chip-danger' => $tone === 'bad',
                                        'cc-chip-info' => $tone === 'info',
                                    ])>{{ $C::ACTIVITY_TYPES[$log->record_type] ?? \Illuminate\Support\Str::headline($log->record_type) }}</span>
                                </td>
                                <td class="py-sm pr-md">{{ $log->description }}</td>
                                <td class="py-sm">{{ \App\Services\ActivityLog::actor($log) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-md text-center text-metadata text-text-secondary">
                                    @if ($query !== '')
                                        No activity matched "{{ $query }}" for {{ $period }}.
                                    @elseif ($type !== '')
                                        No {{ $C::ACTIVITY_TYPES[$type] }} activity for {{ $period }}.
                                @else
                                        No activity recorded on this day.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @include('frontdesk.partials.pagination', ['paginator' => $list, 'noun' => 'activity'])
        @endif
    </div>
@endsection
