@extends('layouts.admin')

@section('title', 'PDL Management')
@section('subtitle', 'Module 1.2 — custody records, classification and restriction status')

@section('content')
    <div class="cc-card">
        <div class="mb-md flex items-center justify-between">
            <div>
                <p class="text-card-title">Persons Deprived of Liberty</p>
                <p class="text-metadata text-text-secondary">{{ count($pdls) }} records</p>
            </div>
            <button type="button" class="cc-btn-primary">+ Register PDL</button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-body">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md">PDL #</th>
                        <th class="py-sm pr-md">Name</th>
                        <th class="py-sm pr-md">Classification</th>
                        <th class="py-sm pr-md">Cell Block</th>
                        <th class="py-sm pr-md">Custody Status</th>
                        <th class="py-sm pr-md">Active Restrictions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($pdls as $pdl)
                        <tr>
                            <td class="py-sm pr-md text-text-secondary">{{ $pdl['pdl_number'] }}</td>
                            <td class="py-sm pr-md font-semibold">{{ $pdl['full_name'] }}</td>
                            <td class="py-sm pr-md">
                                <span class="cc-chip {{ $pdl['classification'] === 'drug_related' ? 'cc-chip-danger' : 'cc-chip-info' }}">
                                    {{ ucwords(str_replace('_', ' ', $pdl['classification'])) }}
                                </span>
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
    </div>
@endsection
