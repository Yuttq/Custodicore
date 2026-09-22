@extends('layouts.admin')

@section('title', 'Visitor Eligibility Assessment')
@section('subtitle', 'Module 1.6 — identity, relationship, history and PDL-restriction checks')

@section('content')
    <div class="cc-card">
        <p class="text-card-title">Recent Assessments</p>

        <div class="mt-md overflow-x-auto">
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
                        <tr>
                            <td class="py-sm pr-md font-semibold">{{ $row['visitor'] }}</td>
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
    </div>

    <div class="cc-card">
        <p class="text-card-title">Visitor Flags</p>
        <p class="text-metadata text-text-secondary">Denied visits, disciplinary issues, rule violations</p>

        <ul class="mt-md divide-y divide-border">
            @foreach ($visitorFlags as $flag)
                <li class="flex items-center justify-between py-sm">
                    <div>
                        <p class="text-body font-semibold">{{ $flag['visitor'] }} — {{ ucwords(str_replace('_', ' ', $flag['flag_type'])) }}</p>
                        <p class="text-metadata text-text-secondary">{{ $flag['description'] }}</p>
                    </div>
                    @include('admin.partials.status-chip', ['status' => $flag['status']])
                </li>
            @endforeach
        </ul>
    </div>
@endsection
