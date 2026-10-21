@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
    <div class="grid grid-cols-1 gap-md sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($stats as $stat)
            @include('admin.partials.stat-card', $stat)
        @endforeach
    </div>

    <div class="cc-card">
        <p class="text-card-title">Visits This Week</p>
        <p class="text-metadata text-text-secondary">Daily visit volume, last 7 days</p>
        <div class="mt-md h-64">
            <canvas id="visitsTrendChart"></canvas>
        </div>
    </div>

    {{-- Month for Peak Hours and the two PDL lists. Opens on last month by default. --}}
    <form method="GET" action="{{ route('admin.dashboard') }}" class="flex flex-wrap items-end gap-sm">
        <label class="flex flex-col text-metadata text-text-secondary">
            Analytics month
            <select name="month" onchange="this.form.submit()"
                    class="h-[38px] rounded-button border border-border bg-white px-sm text-body text-text-primary">
                @foreach ($monthOptions as $value => $label)
                    <option value="{{ $value }}" @selected($value === $selectedMonth)>{{ $label }}@if ($value === $defaultMonth) (previous month)@endif</option>
                @endforeach
            </select>
        </label>
        <noscript><button type="submit" class="cc-btn-secondary">Show</button></noscript>
        <p class="pb-[10px] text-metadata text-text-secondary">Applies to Peak Hours, Most Visited and Least Visited PDLs.</p>
    </form>

    <div class="grid grid-cols-1 gap-lg xl:grid-cols-3">
        <div class="cc-card xl:col-span-2">
            <p class="text-card-title">Peak Hours & Crowd Management</p>
            <p class="text-metadata text-text-secondary">Busiest visiting slots in {{ $monthLabel }}</p>

            @if (empty($peakHours))
                <p class="mt-md text-metadata text-text-secondary">No visiting slots were recorded for {{ $monthLabel }}.</p>
            @else
                <div class="mt-md h-72">
                    <canvas id="peakHoursChart"></canvas>
                </div>

                {{-- What each bar means: its slot, how many were booked, and the capacity used. --}}
                <p class="mt-md text-metadata text-text-secondary">
                    Each bar is one visiting slot. Its height is the share of that slot's capacity that was booked
                    (visitors booked ÷ maximum capacity). Times are in 24-hour format.
                </p>
                @php $barColors = ['#0F3D7A', '#1558A8', '#2F7BC4', '#0DA58A', '#7FD6C6']; @endphp
                <ul class="mt-sm grid grid-cols-1 gap-xs sm:grid-cols-2">
                    @foreach ($peakHours as $i => $slot)
                        <li class="flex items-center gap-sm text-metadata">
                            <span class="h-3 w-3 shrink-0 rounded-sm" style="background: {{ $barColors[$i % count($barColors)] }}"></span>
                            <span class="text-text-primary">{{ $slot['date'] }} · {{ $slot['time'] }}</span>
                            <span class="text-text-secondary">— {{ $slot['taken'] }} of {{ $slot['capacity'] }} booked ({{ $slot['value'] }}%)</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="space-y-lg">
            <div class="cc-card">
                <p class="text-card-title">Most Visited PDLs</p>
                <p class="text-metadata text-text-secondary">Top 5 in {{ $monthLabel }}</p>

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
                        <li class="text-metadata text-text-secondary">No visits recorded in {{ $monthLabel }}.</li>
                    @endforelse
                </ul>
            </div>

            <div class="cc-card">
                <p class="text-card-title">Least Visited PDLs</p>
                <p class="text-metadata text-text-secondary">
                    PDLs in custody with the fewest visits in {{ $monthLabel }}
                    @if (count($leastVisitedPdls) > 5) · scroll to see all {{ count($leastVisitedPdls) }} @endif
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
                            <span class="flex items-center gap-xs text-body font-semibold {{ $pdl['visits_this_month'] === 0 ? 'rounded-chip bg-[#FEF3C7] px-[10px] py-[3px] text-[#B45309]' : 'text-primary-navy' }}">
                                {{ $pdl['visits_this_month'] }}
                            </span>
                        </li>
                    @empty
                        <li class="text-metadata text-text-secondary">No PDLs in custody yet.</li>
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

            // Writes each bar's percentage above it, so nobody has to read it off the axis.
            const valueLabels = {
                id: 'valueLabels',
                afterDatasetsDraw(chart) {
                    const ctx = chart.ctx;
                    ctx.save();
                    ctx.font = '600 12px sans-serif';
                    ctx.fillStyle = '#0F3D7A';
                    ctx.textAlign = 'center';
                    chart.getDatasetMeta(0).data.forEach((bar, i) => {
                        ctx.fillText(peakSlots[i].value + '%', bar.x, bar.y - 6);
                    });
                    ctx.restore();
                },
            };

            new Chart(peakHoursCtx, {
                type: 'bar',
                data: {
                    labels: peakSlots.map((slot) => slot.label),
                    datasets: [{
                        label: 'Capacity booked',
                        data: peakSlots.map((slot) => slot.value),
                        // Navy to teal, one colour per slot (matches the key under the chart).
                        backgroundColor: ['#0F3D7A', '#1558A8', '#2F7BC4', '#0DA58A', '#7FD6C6'],
                        borderRadius: 8,
                        maxBarThickness: 56,
                    }],
                },
                plugins: [valueLabels],
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
                                    return [
                                        `Booked: ${slot.taken} of ${slot.capacity} slots`,
                                        `Capacity used: ${slot.value}%`,
                                    ];
                                },
                            },
                        },
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            title: { display: true, text: 'Capacity booked (% of slot maximum)', color: '#374151', font: { weight: '600' } },
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
