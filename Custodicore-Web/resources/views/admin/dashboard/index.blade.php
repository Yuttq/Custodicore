@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
    <div class="grid grid-cols-1 gap-md sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($stats as $stat)
            @include('admin.partials.stat-card', $stat)
        @endforeach
    </div>

    {{-- "Today's Scheduled Visits" card opens this list. --}}
    <dialog id="todays-visits" class="cc-modal">
        <div class="mb-md flex items-center justify-between">
            <div>
                <p class="text-card-title">Today's Scheduled Visits</p>
                <p class="text-metadata text-text-secondary">{{ now()->format('l, F j, Y') }} · {{ count($todaysVisits) }} {{ count($todaysVisits) === 1 ? 'visit' : 'visits' }} · times in 24-hour format</p>
            </div>
            <button type="button" class="cc-modal-close" onclick="document.getElementById('todays-visits').close()">Close</button>
        </div>

        <div class="max-h-[60vh] overflow-y-auto">
            <table class="w-full text-left text-body">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md">Time</th>
                        <th class="py-sm pr-md">Visitor</th>
                        <th class="py-sm pr-md">PDL</th>
                        <th class="py-sm pr-md">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($todaysVisits as $visit)
                        <tr>
                            <td class="py-sm pr-md text-text-secondary">{{ $visit['time'] }}</td>
                            <td class="py-sm pr-md">
                                @if ($visit['visitor_id'])
                                    <a href="{{ route('admin.visitors.show', $visit['visitor_id']) }}" class="font-semibold hover:underline">{{ $visit['visitor'] }}</a>
                                @else
                                    <span class="font-semibold">{{ $visit['visitor'] }}</span>
                                @endif
                            </td>
                            <td class="py-sm pr-md">
                                {{ $visit['pdl'] }}
                                @if ($visit['pdl_number'])
                                    <span class="block text-metadata text-text-secondary">{{ $visit['pdl_number'] }}</span>
                                @endif
                            </td>
                            <td class="py-sm pr-md">@include('admin.partials.status-chip', ['status' => $visit['status']])</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-lg text-center text-text-secondary">No visits are scheduled for today.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </dialog>
    <script>
        (function () {
            var card = document.querySelector('a[href="#todays-visits"]');
            var dialog = document.getElementById('todays-visits');
            if (card && dialog) {
                card.addEventListener('click', function (e) { e.preventDefault(); dialog.showModal(); });
            }
        })();
    </script>

    <div class="grid grid-cols-1 gap-lg xl:grid-cols-3">
        {{-- Left: weekly trend and peak hours share one column width; Most / Least Visited sit beside them. --}}
        <div class="space-y-lg xl:col-span-2">
            <div class="cc-card">
                <p class="text-card-title">Visits This Week</p>
                <p class="text-metadata text-text-secondary">Daily visit volume, last 7 days</p>
                <div class="mt-md h-64">
                    <canvas id="visitsTrendChart"></canvas>
                </div>
            </div>

            <div class="cc-card">
                <div class="flex flex-wrap items-start justify-between gap-sm">
                    <div>
                        <p class="text-card-title">Peak Hours & Crowd Management</p>
                        <p class="text-metadata text-text-secondary">Top 5 busiest visiting slots in {{ $monthLabel }}</p>
                    </div>
                    {{-- This month filter applies to Peak Hours only. --}}
                    <form method="GET" action="{{ route('admin.dashboard') }}">
                        <label class="flex flex-col text-metadata text-text-secondary">
                            Month
                            <select name="month" onchange="this.form.submit()"
                                    class="h-[38px] rounded-button border border-border bg-white px-sm text-body text-text-primary">
                                @foreach ($monthOptions as $value => $label)
                                    <option value="{{ $value }}" @selected($value === $selectedMonth)>{{ $label }}@if ($value === $defaultMonth) (previous month)@endif</option>
                                @endforeach
                            </select>
                        </label>
                        <noscript><button type="submit" class="cc-btn-secondary mt-xs">Show</button></noscript>
                    </form>
                </div>

                {{-- The chart is always drawn, even when the month has no slots yet. --}}
                <div class="mt-md h-64">
                    <canvas id="peakHoursChart"></canvas>
                </div>
            </div>
        </div>

        <div class="space-y-lg">
            <div class="cc-card">
                <p class="text-card-title">Most Visited PDLs</p>
                <p class="text-metadata text-text-secondary">Top 5 by total visits</p>

                <ul class="mt-md space-y-sm">
                    @forelse ($mostVisitedPdls as $pdl)
                        <li class="flex items-center justify-between gap-sm border-b border-border pb-sm last:border-0 last:pb-0">
                            <div class="flex items-center gap-sm">
                                @include('admin.partials.avatar', ['name' => $pdl['full_name'], 'size' => 'h-9 w-9'])
                                <div>
                                    <p class="text-body font-semibold leading-tight">{{ $pdl['full_name'] }}</p>
                                    <p class="text-metadata text-text-secondary">{{ $pdl['pdl_number'] }}</p>
                                </div>
                            </div>
                            <span class="flex items-center gap-xs text-body font-semibold text-primary-navy">
                                {{ $pdl['visits_this_month'] }}
                            </span>
                        </li>
                    @empty
                        <li class="text-metadata text-text-secondary">No visits recorded yet.</li>
                    @endforelse
                </ul>
            </div>

            <div class="cc-card">
                <p class="text-card-title">Least Visited PDLs</p>
                <p class="text-metadata text-text-secondary">
                    PDLs in custody with no visits
                    <span class="cc-chip cc-chip-neutral ml-xs">{{ count($leastVisitedPdls) }}</span>
                    @if (count($leastVisitedPdls) > 5) · scroll to see all @endif
                </p>

                {{-- Shows about five rows; the rest are reached by scrolling inside the card. --}}
                <ul class="mt-md max-h-[19.5rem] space-y-sm overflow-y-auto pr-sm">
                    @forelse ($leastVisitedPdls as $pdl)
                        <li class="flex items-center justify-between gap-sm border-b border-border pb-sm last:border-0 last:pb-0">
                            <div class="flex items-center gap-sm">
                                @include('admin.partials.avatar', ['name' => $pdl['full_name'], 'size' => 'h-9 w-9'])
                                <div>
                                    <p class="text-body font-semibold leading-tight">{{ $pdl['full_name'] }}</p>
                                    <p class="text-metadata text-text-secondary">
                                        {{ $pdl['pdl_number'] }} ·
                                        {{ $pdl['last_visit'] ? 'Last visit ' . $pdl['last_visit'] : 'No visit on record' }}
                                    </p>
                                </div>
                            </div>
                            <span class="flex items-center gap-xs rounded-chip bg-[#FEF3C7] px-[10px] py-[3px] text-body font-semibold text-[#B45309]">0</span>
                        </li>
                    @empty
                        <li class="text-metadata text-text-secondary">Every PDL in custody has had at least one visit.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
    {{-- Chart.js loads from resources/js/app.js, a deferred module script,
         so it may not have run yet at this point in the page — waiting for
         DOMContentLoaded guarantees window.Chart is set before we use it. --}}
    document.addEventListener('DOMContentLoaded', function () {
        const trendCtx = document.getElementById('visitsTrendChart');
        if (trendCtx && window.Chart) {
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: {!! json_encode($visitsTrend['labels']) !!},
                    datasets: [{
                        label: 'Visits',
                        data: {!! json_encode($visitsTrend['values']) !!},
                        fill: true,
                        backgroundColor: 'rgba(13, 165, 138, 0.15)',
                        borderColor: '#0DA58A',
                        borderWidth: 3,
                        tension: 0.35,
                        pointBackgroundColor: '#FFFFFF',
                        pointBorderColor: '#0DA58A',
                        pointBorderWidth: 2.5,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#E5E7EB' }, ticks: { color: '#6B7280' } },
                        x: { grid: { display: false }, ticks: { color: '#6B7280' } },
                    },
                },
            });
        }

        const peakHoursCtx = document.getElementById('peakHoursChart');
        if (peakHoursCtx && window.Chart) {
            const peakSlots = {!! json_encode($peakHours) !!};

            // Writes the % on top of every bar so the value is readable without hovering.
            const barValues = {
                id: 'barValues',
                afterDatasetsDraw(chart) {
                    const { ctx } = chart;
                    ctx.save();
                    ctx.font = '600 12px sans-serif';
                    ctx.fillStyle = '#0F3D7A';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'bottom';
                    chart.getDatasetMeta(0).data.forEach((bar, i) => {
                        if (peakSlots[i]) ctx.fillText(peakSlots[i].value + '%', bar.x, bar.y - 4);
                    });
                    ctx.restore();
                },
            };

            // With no slots in the month, keep the empty chart and say why on top of it.
            const emptyMessage = {
                id: 'emptyMessage',
                afterDraw(chart) {
                    if (peakSlots.length > 0) return;
                    const { ctx, chartArea } = chart;
                    ctx.save();
                    ctx.font = '600 14px sans-serif';
                    ctx.fillStyle = '#6B7280';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText({!! json_encode('No visiting slots recorded for ' . $monthLabel) !!}, (chartArea.left + chartArea.right) / 2, (chartArea.top + chartArea.bottom) / 2);
                    ctx.restore();
                },
            };

            new Chart(peakHoursCtx, {
                type: 'bar',
                data: {
                    // Two lines per bar: the date, then the time window (24-hour).
                    labels: peakSlots.map((slot) => [slot.day, slot.time]),
                    datasets: [{
                        label: 'Capacity used',
                        data: peakSlots.map((slot) => slot.value),
                        // Navy to teal, one colour per slot (repeats if there are more slots).
                        backgroundColor: ['#0F3D7A', '#1558A8', '#2F7BC4', '#0DA58A', '#7FD6C6'],
                        borderRadius: 8,
                        maxBarThickness: 56,
                    }],
                },
                plugins: [emptyMessage, barValues],
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: { top: 22 } },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                title: (items) => peakSlots[items[0].dataIndex].date + ' · ' + peakSlots[items[0].dataIndex].time,
                                label: (item) => {
                                    const slot = peakSlots[item.dataIndex];
                                    return `${item.parsed.y}% of capacity (${slot.taken} of ${slot.capacity} booked)`;
                                },
                            },
                        },
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            title: { display: true, text: 'Capacity used (% of slot booked)', color: '#374151', font: { weight: '600' } },
                            grid: { color: '#E5E7EB' },
                            ticks: { color: '#6B7280', callback: (value) => value + '%' },
                        },
                        x: {
                            title: { display: true, text: 'Visiting slot (date and time, 24-hour)', color: '#374151', font: { weight: '600' } },
                            grid: { display: false },
                            ticks: { color: '#6B7280' },
                        },
                    },
                },
            });
        }
    });
    </script>
@endpush
