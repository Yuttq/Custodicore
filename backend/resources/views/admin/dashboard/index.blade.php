@extends('layouts.admin')

@section('title', 'Dashboard')
@section('subtitle', 'System-wide overview — Module 1.7 Audit Trail and Basic Reporting')

@section('content')
    <div class="grid grid-cols-1 gap-md sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($stats as $stat)
            <div class="cc-card">
                <p class="text-section-label text-text-secondary">{{ $stat['label'] }}</p>
                <p class="mt-xs text-page-title">{{ $stat['value'] }}</p>
                <p class="mt-xs text-metadata text-{{ $stat['accent'] }}">{{ $stat['hint'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 gap-lg xl:grid-cols-3">
        <div class="cc-card xl:col-span-2">
            <p class="text-card-title">Peak Hours & Crowd Management</p>
            <p class="text-metadata text-text-secondary">Busiest visiting slots this month</p>

            <div class="mt-md space-y-sm">
                @foreach ($peakHours as $row)
                    <div>
                        <div class="mb-xs flex items-center justify-between text-metadata">
                            <span>{{ $row['label'] }}</span>
                            <span class="text-text-secondary">{{ $row['value'] }}%</span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-chip bg-border">
                            <div class="h-full rounded-chip bg-primary-teal" style="width: {{ $row['value'] }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="cc-card">
            <p class="text-card-title">Most Visited PDLs</p>
            <p class="text-metadata text-text-secondary">This month</p>

            <ul class="mt-md space-y-sm">
                @foreach ($mostVisitedPdls as $pdl)
                    <li class="flex items-center justify-between border-b border-border pb-sm last:border-0 last:pb-0">
                        <div>
                            <p class="text-body font-semibold">{{ $pdl['full_name'] }}</p>
                            <p class="text-metadata text-text-secondary">{{ $pdl['pdl_number'] }}</p>
                        </div>
                        <span class="text-body font-semibold text-primary-navy">{{ $pdl['visits_this_month'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>

    <div class="cc-card">
        <p class="text-card-title">Recent Activity</p>
        <p class="text-metadata text-text-secondary">Latest entries from the audit trail</p>

        <ul class="mt-md divide-y divide-border">
            @foreach ($recentActivity as $event)
                <li class="flex items-center justify-between py-sm">
                    <span class="text-body">{{ $event['description'] }}</span>
                    <span class="text-metadata text-text-secondary">{{ $event['created_at'] }}</span>
                </li>
            @endforeach
        </ul>
    </div>
@endsection
