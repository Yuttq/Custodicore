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
                <p class="text-metadata text-text-secondary">
                    @if ($search)
                        {{ count($pdls) }} of {{ $totalPdls }} records match "{{ $search }}"
                    @else
                        {{ count($pdls) }} records · read-only
                    @endif
                </p>
            </div>
            {{-- Working search: name (first / middle / last / alias), PDL # or cell block. --}}
            <form method="GET" action="{{ route('admin.pdls.index') }}" class="flex items-center gap-sm">
                <input type="search" name="q" value="{{ $search }}" maxlength="100"
                       placeholder="Search name, PDL # or cell block…"
                       class="h-[38px] w-full rounded-button border border-border bg-white px-md text-body sm:w-72" />
                <button type="submit" class="cc-btn-secondary shrink-0">Search</button>
                @if ($search)
                    <a href="{{ route('admin.pdls.index') }}" class="cc-btn-secondary shrink-0">Clear</a>
                @endif
            </form>
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
                        <th class="py-sm pr-md">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($pdls as $pdl)
                        <tr class="transition hover:bg-background">
                            <td class="py-sm pr-md text-text-secondary">{{ $pdl['pdl_number'] }}</td>
                            <td class="py-sm pr-md">
                                <div class="flex items-center gap-sm">
                                    @include('admin.partials.avatar', ['name' => $pdl['full_name'], 'size' => 'h-8 w-8'])
                                    <a href="{{ route('admin.pdls.show', $pdl['id']) }}" class="font-semibold hover:underline">{{ $pdl['full_name'] }}</a>
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
                            <td class="py-sm pr-md">
                                <a href="{{ route('admin.pdls.show', $pdl['id']) }}" class="cc-btn-ghost">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-lg text-center text-text-secondary">
                                {{ $search ? 'No PDL matches your search.' : 'No PDL records yet.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('admin.partials.pagination-footer', ['total' => count($pdls), 'label' => 'records'])
    </div>
@endsection
