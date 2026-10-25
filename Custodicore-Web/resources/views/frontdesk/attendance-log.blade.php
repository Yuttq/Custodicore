@extends('layouts.frontdesk')

@section('title', 'Attendance Log')

@section('content')
    @php $period = $date->isToday() ? 'today' : $date->format('l, F j, Y'); @endphp

    <div class="cc-card">
        <div class="flex flex-wrap items-center justify-between gap-md">
            <p class="text-card-title">Gate Activity</p>

            <form method="GET" action="{{ route('frontdesk.attendance-log') }}" class="flex flex-wrap items-center justify-end gap-sm">
                @include('frontdesk.partials.search-input', ['id' => 'attendanceQuery', 'value' => $query, 'placeholder' => 'Search visitor, PDL or officer…'])

                <label for="attendanceMethod" class="sr-only">Method</label>
                <select id="attendanceMethod" name="method" onchange="this.form.submit()" class="cc-input !mt-0 w-auto">
                    <option value="">All methods</option>
                    @foreach (\App\Http\Controllers\FrontDesk\AttendanceLogController::METHODS as $key => $label)
                        <option value="{{ $key }}" @selected($method === $key)>{{ $label }}</option>
                    @endforeach
                </select>

                @php $dayLink = fn ($d) => route('frontdesk.attendance-log', array_filter(['date' => $d->isToday() ? null : $d->format('Y-m-d'), 'q' => $query, 'method' => $method])); @endphp
                @include('frontdesk.partials.date-nav', [
                    'value' => $date->format('Y-m-d'),
                    'prevUrl' => $dayLink($date->copy()->subDay()),
                    'nextUrl' => $date->isToday() ? null : $dayLink($date->copy()->addDay()),
                    'max' => today(),
                ])

                {{-- Enter in the search box also submits. --}}
                <button type="submit" class="sr-only">Search</button>
                @if ($filtered)
                    <a href="{{ route('frontdesk.attendance-log') }}" class="cc-btn-secondary">Clear</a>
                @endif
            </form>
        </div>

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
                            <td class="py-sm pr-md">{{ $in?->format('H:i') ?? '—' }}</td>
                            <td class="py-sm pr-md">{{ $out?->format('H:i') ?? '—' }}</td>
                            <td class="py-sm pr-md">
                                <span class="block"><span class="text-metadata text-text-secondary">In:</span> {{ $entry->checkInOfficer?->full_name ?? '—' }}</span>
                                <span class="block"><span class="text-metadata text-text-secondary">Out:</span> {{ $entry->checkOutOfficer?->full_name ?? ($out ? '—' : 'Not yet') }}</span>
                            </td>
                            <td class="py-sm pr-md">
                                {{ $entry->id_surrendered_type ? (new \App\Models\VisitorId(['id_type' => $entry->id_surrendered_type]))->typeLabel() : '—' }}
                                @if ($entry->id_returned_time)
                                    <span class="block text-metadata text-success">Returned {{ $entry->id_returned_time->format('H:i') }}</span>
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
                                    No {{ \App\Http\Controllers\FrontDesk\AttendanceLogController::METHODS[$method] }} entries for {{ $period }}.
                                @else
                                    No visitors checked in on this day.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('frontdesk.partials.pagination', ['paginator' => $entries, 'noun' => 'entry'])
    </div>
@endsection
