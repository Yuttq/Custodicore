@extends('layouts.admin')

@section('title', 'PDL Management')

@section('content')
    <div class="grid grid-cols-1 gap-md sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($summary as $stat)
            @include('admin.partials.stat-card', $stat)
        @endforeach
    </div>

    <div class="cc-card">
        <div class="mb-md flex flex-col gap-sm lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="text-card-title">Persons Deprived of Liberty</p>
                <p class="text-metadata text-text-secondary">{{ count($pdls) }} records · read-only</p>
            </div>
            @include('admin.partials.search-box', ['placeholder' => 'Search by name or PDL #…'])
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-body">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md">PDL #</th>
                        <th class="py-sm pr-md">Name</th>
                        <th class="py-sm pr-md">Cell Block</th>
                        <th class="py-sm pr-md">Custody Status</th>
                        <th class="py-sm pr-md">Active Restrictions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($pdls as $pdl)
                        <tr class="transition hover:bg-background">
                            <td class="py-sm pr-md text-text-secondary">{{ $pdl['pdl_number'] }}</td>
                            <td class="py-sm pr-md">
                                <div class="flex items-center gap-sm">
                                    @include('admin.partials.avatar', ['name' => $pdl['full_name'], 'size' => 'h-8 w-8'])
                                    <span class="font-semibold">{{ $pdl['full_name'] }}</span>
                                </div>
                            </td>
                            <td class="py-sm pr-md text-text-secondary">{{ $pdl['cell_block'] }}</td>
                            <td class="py-sm pr-md">@include('admin.partials.status-chip', ['status' => $pdl['custody_status']])</td>
                            <td class="py-sm pr-md">
                                @if ($pdl['active_restrictions'] > 0)
                                    <span class="cc-chip cc-chip-danger">{{ $pdl['active_restrictions'] }} active</span>
                                @else
                                    <span class="cc-chip cc-chip-success">None</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @include('admin.partials.pagination-footer', ['total' => count($pdls), 'label' => 'records'])
    </div>
@endsection
