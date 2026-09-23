@extends('layouts.admin')

@section('title', 'Visitor Management')

@section('content')
    <div class="grid grid-cols-1 gap-md sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($summary as $stat)
            @include('admin.partials.stat-card', $stat)
        @endforeach
    </div>

    <div class="cc-card">
        <div class="mb-md flex flex-col gap-sm lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="text-card-title">Registered Visitors</p>
                <p class="text-metadata text-text-secondary">{{ count($visitors) }} visitors · read-only</p>
            </div>
            @include('admin.partials.search-box', ['placeholder' => 'Search by name or contact #…'])
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-body">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md">Name</th>
                        <th class="py-sm pr-md">Contact</th>
                        <th class="py-sm pr-md">Related PDL</th>
                        <th class="py-sm pr-md">PDL #</th>
                        <th class="py-sm pr-md">Relationship</th>
                        <th class="py-sm pr-md">Verification</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($visitors as $visitor)
                        <tr class="transition hover:bg-background">
                            <td class="py-sm pr-md">
                                <div class="flex items-center gap-sm">
                                    @include('admin.partials.avatar', ['name' => $visitor['full_name'], 'size' => 'h-8 w-8'])
                                    <span class="font-semibold">{{ $visitor['full_name'] }}</span>
                                </div>
                            </td>
                            <td class="py-sm pr-md text-text-secondary">{{ $visitor['contact_number'] }}</td>
                            <td class="py-sm pr-md">{{ $visitor['related_pdl_name'] }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ $visitor['related_pdl_number'] }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ ucwords(str_replace('_', ' ', $visitor['relationship_type'])) }}</td>
                            <td class="py-sm pr-md">@include('admin.partials.status-chip', ['status' => $visitor['verification_status']])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @include('admin.partials.pagination-footer', ['total' => count($visitors), 'label' => 'visitors'])
    </div>
@endsection
