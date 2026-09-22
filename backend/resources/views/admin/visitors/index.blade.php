@extends('layouts.admin')

@section('title', 'Visitor Management')
@section('subtitle', 'Module 1.3 — mobile-registered visitor profiles and verification status')

@section('content')
    <div class="cc-card">
        <div class="mb-md flex items-center justify-between">
            <div>
                <p class="text-card-title">Registered Visitors</p>
                <p class="text-metadata text-text-secondary">{{ count($visitors) }} visitors</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-body">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md">Name</th>
                        <th class="py-sm pr-md">Contact</th>
                        <th class="py-sm pr-md">Related PDL</th>
                        <th class="py-sm pr-md">Relationship</th>
                        <th class="py-sm pr-md">Verification</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($visitors as $visitor)
                        <tr>
                            <td class="py-sm pr-md font-semibold">{{ $visitor['full_name'] }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ $visitor['contact_number'] }}</td>
                            <td class="py-sm pr-md">{{ $visitor['related_pdl'] }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ ucwords(str_replace('_', ' ', $visitor['relationship_type'])) }}</td>
                            <td class="py-sm pr-md">@include('admin.partials.status-chip', ['status' => $visitor['verification_status']])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
