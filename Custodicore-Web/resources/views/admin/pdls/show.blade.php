@extends('layouts.admin')

@section('title', $pdl->full_name)

@php
    $classification = [
        'drug_related' => 'Drug-related',
        'non_drug_related' => 'Non-drug-related',
    ][$pdl->classification] ?? '—';
    $photo = $pdl->photo_path
        ? route('admin.pdls.photo', $pdl->pdl_id) . '?v=' . substr(md5($pdl->photo_path), 0, 8)
        : null;
@endphp

@section('content')
    <div>
        <a href="{{ route('admin.pdls.index') }}" class="cc-btn-secondary">Back to PDL Management</a>
    </div>

    {{-- ================= SUMMARY ================= --}}
    <div class="cc-card">
        <div class="flex flex-wrap items-center gap-md">
            @if ($photo)
                <img src="{{ $photo }}" alt="Photo of {{ $pdl->full_name }}" class="h-20 w-20 rounded-card border border-border object-cover">
            @else
                @include('admin.partials.avatar', ['name' => $pdl->full_name, 'size' => 'h-20 w-20'])
            @endif
            <div>
                <p class="text-page-title">{{ $pdl->full_name }}</p>
                <p class="text-metadata text-text-secondary">
                    {{ $pdl->pdl_number }}@if ($pdl->alias) · alias "{{ $pdl->alias }}"@endif
                </p>
                <div class="mt-sm">@include('admin.partials.status-chip', ['status' => $pdl->custody_status])</div>
            </div>
            <p class="ml-auto text-metadata text-text-secondary">Read-only view. Records are managed by the Record Officer.</p>
        </div>
    </div>

    {{-- ================= PROFILE ================= --}}
    <div class="cc-card">
        <p class="mb-md text-card-title">Profile Details</p>
        <dl class="grid grid-cols-1 gap-md sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                'First Name' => $nameParts['first'] ?: '—',
                'Middle Name' => $nameParts['middle'] ?: '—',
                'Last Name' => $nameParts['last'] ?: '—',
                'Alias' => $pdl->alias ?: '—',
                'Gender' => $pdl->gender ? ucfirst($pdl->gender) : '—',
                'Date of Birth' => $pdl->date_of_birth?->format('M j, Y') ?? '—',
                'Age' => $pdl->date_of_birth ? $pdl->date_of_birth->age . ' years old' : '—',
                'Classification' => $classification,
                'Admission Date' => $pdl->admission_date?->format('M j, Y') ?? '—',
                'Cell / Block' => $pdl->cell_block ?: 'Not assigned',
                'Custody Status' => ucfirst($pdl->custody_status),
            ] as $label => $value)
                <div>
                    <dt class="text-section-label uppercase text-text-secondary">{{ $label }}</dt>
                    <dd class="mt-xs text-body font-semibold">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </div>

    {{-- ================= LEGAL RECORDS ================= --}}
    <div class="cc-card">
        <p class="text-card-title">Legal Records <span class="cc-chip cc-chip-neutral ml-xs">{{ $pdl->legalRecords->count() }}</span></p>

        @if ($pdl->legalRecords->isEmpty())
            <p class="mt-md text-body text-text-secondary">No legal records on file.</p>
        @else
            <div class="mt-md overflow-x-auto">
                <table class="w-full text-left text-body">
                    <thead>
                        <tr class="border-b border-border text-section-label text-text-secondary">
                            <th class="py-sm pr-md">Case Number</th>
                            <th class="py-sm pr-md">Offense</th>
                            <th class="py-sm pr-md">Court</th>
                            <th class="py-sm pr-md">Status</th>
                            <th class="py-sm pr-md">Remarks</th>
                            <th class="py-sm pr-md">Recorded</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($pdl->legalRecords as $record)
                            <tr>
                                <td class="py-sm pr-md font-semibold">{{ $record->case_number }}</td>
                                <td class="py-sm pr-md">{{ $record->offense }}</td>
                                <td class="py-sm pr-md text-text-secondary">{{ $record->court ?: '—' }}</td>
                                <td class="py-sm pr-md text-text-secondary">{{ $record->case_status ?: '—' }}</td>
                                <td class="py-sm pr-md text-text-secondary">{{ $record->remarks ?: '—' }}</td>
                                <td class="py-sm pr-md text-text-secondary">{{ $record->created_at?->format('M j, Y') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ================= DISCIPLINARY RECORDS ================= --}}
    <div class="cc-card">
        <p class="text-card-title">Disciplinary Records <span class="cc-chip cc-chip-neutral ml-xs">{{ $pdl->disciplinaryRecords->count() }}</span></p>

        @if ($pdl->disciplinaryRecords->isEmpty())
            <p class="mt-md text-body text-text-secondary">No disciplinary records on file.</p>
        @else
            <div class="mt-md overflow-x-auto">
                <table class="w-full text-left text-body">
                    <thead>
                        <tr class="border-b border-border text-section-label text-text-secondary">
                            <th class="py-sm pr-md">Incident Date</th>
                            <th class="py-sm pr-md">Description</th>
                            <th class="py-sm pr-md">Action Taken</th>
                            <th class="py-sm pr-md">Led to Restriction</th>
                            <th class="py-sm pr-md">Recorded</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($pdl->disciplinaryRecords as $record)
                            <tr>
                                <td class="py-sm pr-md font-semibold">{{ $record->incident_date?->format('M j, Y') ?? '—' }}</td>
                                <td class="py-sm pr-md">{{ $record->description }}</td>
                                <td class="py-sm pr-md text-text-secondary">{{ $record->action_taken ?: '—' }}</td>
                                <td class="py-sm pr-md">
                                    @if ($record->triggers_restriction)
                                        <span class="cc-chip cc-chip-danger">Yes</span>
                                    @else
                                        <span class="cc-chip cc-chip-neutral">No</span>
                                    @endif
                                </td>
                                <td class="py-sm pr-md text-text-secondary">{{ $record->created_at?->format('M j, Y') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
