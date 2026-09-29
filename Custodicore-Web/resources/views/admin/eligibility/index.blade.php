@extends('layouts.admin')

@section('title', 'Visitor Eligibility Assessment')
@section('subtitle', 'Module 1.6 — identity, relationship, history and PDL-restriction checks')

@section('content')
    <div class="grid grid-cols-1 gap-md sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($summary as $stat)
            @include('admin.partials.stat-card', $stat)
        @endforeach
    </div>

    <div class="cc-card">
        <div class="mb-md flex flex-col gap-sm lg:flex-row lg:items-center lg:justify-between">
            <p class="text-card-title">Recent Assessments</p>
            @include('admin.partials.search-box', ['placeholder' => 'Search by visitor or PDL…'])
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-body">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md">Visitor</th>
                        <th class="py-sm pr-md">PDL</th>
                        <th class="py-sm pr-md">Identity</th>
                        <th class="py-sm pr-md">Relationship</th>
                        <th class="py-sm pr-md">History</th>
                        <th class="py-sm pr-md">Restriction</th>
                        <th class="py-sm pr-md">Overall</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($assessments as $row)
                        <tr class="transition hover:bg-background">
                            <td class="py-sm pr-md">
                                <div class="flex items-center gap-sm">
                                    @include('admin.partials.avatar', ['name' => $row['visitor'], 'size' => 'h-8 w-8'])
                                    <span class="font-semibold">{{ $row['visitor'] }}</span>
                                </div>
                            </td>
                            <td class="py-sm pr-md">{{ $row['pdl'] }}</td>
                            <td class="py-sm pr-md">@include('admin.partials.status-chip', ['status' => $row['identity_check']])</td>
                            <td class="py-sm pr-md">@include('admin.partials.status-chip', ['status' => $row['relationship_check']])</td>
                            <td class="py-sm pr-md">@include('admin.partials.status-chip', ['status' => $row['history_check']])</td>
                            <td class="py-sm pr-md">@include('admin.partials.status-chip', ['status' => $row['restriction_check']])</td>
                            <td class="py-sm pr-md">@include('admin.partials.status-chip', ['status' => $row['overall_result']])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @include('admin.partials.pagination-footer', ['total' => count($assessments), 'label' => 'assessments'])
    </div>

    <div class="cc-card">
        <p class="text-card-title">Visitor Flags</p>
        <p class="text-metadata text-text-secondary">Denied visits, disciplinary issues, rule violations</p>

        <ul class="mt-md divide-y divide-border">
            @foreach ($visitorFlags as $flag)
                <li class="flex items-center gap-sm py-sm">
                    @include('admin.partials.avatar', ['name' => $flag['visitor'], 'size' => 'h-9 w-9'])
                    <div class="flex-1">
                        <p class="text-body font-semibold">{{ $flag['visitor'] }} — {{ ucwords(str_replace('_', ' ', $flag['flag_type'])) }}</p>
                        <p class="text-metadata text-text-secondary">{{ $flag['description'] }}</p>
                    </div>
                    @include('admin.partials.status-chip', ['status' => $flag['status']])
                </li>
            @endforeach
        </ul>
    </div>
@endsection
