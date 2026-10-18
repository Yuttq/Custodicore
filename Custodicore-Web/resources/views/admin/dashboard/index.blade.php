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

    <div class="grid grid-cols-1 gap-lg xl:grid-cols-3">
        <div class="cc-card xl:col-span-2">
            <p class="text-card-title">Peak Hours & Crowd Management</p>
            <p class="text-metadata text-text-secondary">Busiest visiting slots this month</p>
            <div class="mt-md h-64">
                <canvas id="peakHoursChart"></canvas>
            </div>
        </div>

        <div class="space-y-lg">
            <div class="cc-card">
                <p class="text-card-title">Most Visited PDLs</p>
                <p class="text-metadata text-text-secondary">This month</p>

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
                        <li class="text-metadata text-text-secondary">No visits recorded this month yet.</li>
                    @endforelse
                </ul>
            </div>

            <div class="cc-card">
                <p class="text-card-title">Least Visited PDLs</p>
                <p class="text-metadata text-text-secondary">PDLs in custody with the fewest visits this month</p>

                <ul class="mt-md space-y-sm">
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
            new Chart(peakHoursCtx, {
                type: 'bar',
                data: {
                    labels: {!! json_encode(array_column($peakHours, 'label')) !!},
                    datasets: [{
                        label: 'Capacity used',
                        data: {!! json_encode(array_column($peakHours, 'value')) !!},
                        // Navy to teal, one colour per slot (repeats if there are more slots).
                        backgroundColor: ['#0F3D7A', '#1558A8', '#2F7BC4', '#0DA58A', '#7FD6C6'],
                        borderRadius: 8,
                        maxBarThickness: 56,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: (item) => `${item.parsed.y}% of capacity`,
                            },
                        },
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            grid: { color: '#E5E7EB' },
                            ticks: { color: '#6B7280', callback: (value) => value + '%' },
                        },
                        x: { grid: { display: false }, ticks: { color: '#6B7280' } },
                    },
                },
            });
        }
    });
    </script>
@endpush
