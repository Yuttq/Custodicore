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
            <p class="text-card-title">Recent Activity</p>
            @include('admin.partials.search-box', ['placeholder' => 'Search by account or description…'])
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
                    @foreach ($auditLogs as $log)
                        <tr class="transition hover:bg-background">
                            <td class="py-sm pr-md text-text-secondary">{{ $log['created_at'] }}</td>
                            <td class="py-sm pr-md">
                                <div class="flex items-center gap-sm">
                                    @include('admin.partials.avatar', [
                                        'name' => $log['account'] === 'system' ? 'SY' : $log['account'],
                                        'size' => 'h-8 w-8',
                                        'color' => $log['account'] === 'system' ? 'bg-text-secondary' : null,
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
                    @endforeach
                </tbody>
            </table>
        </div>

        @include('admin.partials.pagination-footer', ['total' => count($auditLogs), 'label' => 'entries'])
    </div>
@endsection
