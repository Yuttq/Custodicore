@extends('layouts.admin')

@section('title', 'PDL Profile')
@section('heading', '')

@php
    $classification = [
        'drug_related' => 'Drug-related',
        'non_drug_related' => 'Non-drug-related',
    ][$pdl->classification] ?? null;
    $photo = $pdl->photo_path
        ? route('admin.pdls.photo', $pdl->pdl_id) . '?v=' . substr(md5($pdl->photo_path), 0, 8)
        : null;

    $daysInCustody = $pdl->admission_date
        ? abs((int) $pdl->admission_date->copy()->startOfDay()->diffInDays(now()->startOfDay()))
        : null;

    $facts = [
        'Age' => $pdl->date_of_birth ? $pdl->date_of_birth->age . ' yrs' : '—',
        'Gender' => $pdl->gender ? ucfirst($pdl->gender) : '—',
        'Born' => $pdl->date_of_birth?->format('M j, Y') ?? '—',
        'Cell / Block' => $pdl->cell_block ?: 'Not assigned',
        'Admitted' => $pdl->admission_date?->format('M j, Y') ?? '—',
        'In Custody' => $daysInCustody !== null
            ? number_format($daysInCustody) . ' ' . ($daysInCustody === 1 ? 'day' : 'days')
            : '—',
    ];
    $disciplinaryCount = $pdl->disciplinaryRecords->count();
@endphp

@section('content')
    <div>
        <a href="{{ route('admin.pdls.index') }}" class="cc-btn-secondary">Back</a>
    </div>

    {{-- One profile card: banner (photo, name, status), quick facts, then the records. --}}
    <div class="overflow-hidden rounded-card border border-border bg-card shadow-card">
        <div class="flex items-center gap-md bg-primary-navy px-md py-md sm:px-lg">
            {{-- Thumbnail; tap to open the full-size photo. --}}
            <div class="aspect-[4/5] w-20 shrink-0 overflow-hidden rounded-sm border-2 border-white/40 bg-white/10 sm:w-24">
                @if ($photo)
                    <a href="{{ $photo }}" target="_blank" rel="noopener" title="Open full-size photo" class="block h-full w-full">
                        <img src="{{ $photo }}" alt="Photo of {{ $pdl->full_name }}" class="h-full w-full object-cover">
                    </a>
                @else
                    @include('admin.partials.person-silhouette')
                @endif
            </div>

            <div class="min-w-0 flex-1">
                <p class="break-words text-card-title text-white sm:text-page-title">{{ $pdl->full_name }}</p>
                <p class="text-metadata text-white/70">
                    {{ $pdl->pdl_number }}@if ($pdl->alias) · alias "{{ $pdl->alias }}"@endif
                </p>
                <div class="mt-sm flex flex-wrap gap-xs">
                    @include('admin.partials.status-chip', ['status' => $pdl->custody_status])
                    @if ($classification)
                        <span class="cc-chip cc-chip-info">{{ $classification }}</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="space-y-lg p-md">
            {{-- Quick facts: 2 columns on phones, 3 on wider screens. --}}
            <dl class="grid grid-cols-2 gap-x-md gap-y-sm rounded-sm border border-border p-md sm:grid-cols-3">
                @foreach ($facts as $label => $value)
                    <div class="min-w-0">
                        <dt class="text-section-label uppercase text-text-secondary">{{ $label }}</dt>
                        <dd class="text-body font-semibold text-primary-navy">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="grid grid-cols-1 gap-lg lg:grid-cols-2">
                {{-- Legal records --}}
                <section>
                    <p class="text-card-title text-primary-navy">Legal Records <span class="cc-chip cc-chip-neutral ml-xs">{{ $pdl->legalRecords->count() }}</span></p>

                    @if ($pdl->legalRecords->isEmpty())
                        <p class="mt-xs text-metadata text-text-secondary">No legal records on file.</p>
                    @else
                        <div class="mt-sm divide-y divide-border rounded-sm border border-border">
                            @foreach ($pdl->legalRecords as $record)
                                <div class="px-md py-sm">
                                    <div class="flex flex-wrap items-center justify-between gap-xs">
                                        <p class="text-body font-semibold">{{ $record->offense ?: '—' }}</p>
                                        @if ($record->case_status)
                                            <span class="cc-chip cc-chip-neutral">{{ ucfirst($record->case_status) }}</span>
                                        @endif
                                    </div>
                                    <p class="text-metadata text-text-secondary">
                                        Case {{ $record->case_number }} · {{ $record->court ?: 'Court not recorded' }} · {{ $record->created_at?->format('M j, Y') ?? '—' }}
                                    </p>
                                    @if ($record->remarks)
                                        <p class="text-metadata text-text-secondary">Remarks: {{ $record->remarks }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>

                {{-- Disciplinary records --}}
                <section>
                    <p class="text-card-title text-primary-navy">Disciplinary Records <span class="cc-chip {{ $disciplinaryCount ? 'cc-chip-warning' : 'cc-chip-neutral' }} ml-xs">{{ $disciplinaryCount }}</span></p>

                    @if ($disciplinaryCount === 0)
                        <p class="mt-xs text-metadata text-text-secondary">None on file — clean conduct record.</p>
                    @else
                        <div class="mt-sm divide-y divide-border rounded-sm border border-border">
                            @foreach ($pdl->disciplinaryRecords as $record)
                                <div class="px-md py-sm">
                                    <div class="flex flex-wrap items-center justify-between gap-xs">
                                        <p class="text-body font-semibold">{{ $record->incident_date?->format('M j, Y') ?? '—' }}</p>
                                        @if ($record->ledToRestriction())
                                            <span class="cc-chip cc-chip-danger">Led to restriction</span>
                                        @else
                                            <span class="cc-chip cc-chip-neutral">No restriction</span>
                                        @endif
                                    </div>
                                    <p class="text-body">{{ $record->description }}</p>
                                    <p class="text-metadata text-text-secondary">
                                        Action taken: {{ $record->action_taken ?: '—' }} · Recorded {{ $record->created_at?->format('M j, Y') ?? '—' }}
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </section>
            </div>

            {{-- Authorized visitors --}}
            <section>
                <p class="text-card-title text-primary-navy">Authorized Visitors <span class="cc-chip {{ $authorizedVisitors->count() ? 'cc-chip-success' : 'cc-chip-neutral' }} ml-xs">{{ $authorizedVisitors->count() }}</span></p>
                <p class="text-metadata text-text-secondary">Verified relationship and verified visitor account</p>

                @if ($authorizedVisitors->isEmpty())
                    <p class="mt-xs text-metadata text-text-secondary">No authorized visitors yet.</p>
                @else
                    <div class="mt-sm divide-y divide-border rounded-sm border border-border">
                        @foreach ($authorizedVisitors as $rel)
                            <div class="flex items-center justify-between gap-sm px-md py-sm">
                                <div class="flex min-w-0 items-center gap-sm">
                                    @include('admin.partials.avatar', ['name' => $rel->visitor->full_name, 'size' => 'h-9 w-9'])
                                    <div class="min-w-0">
                                        <a href="{{ route('admin.visitors.show', $rel->visitor_id) }}"
                                           class="block truncate text-body font-semibold text-primary-navy hover:underline">{{ $rel->visitor->full_name }}</a>
                                        <p class="text-metadata text-text-secondary">
                                            Verified {{ $rel->verified_at ? \Illuminate\Support\Carbon::parse($rel->verified_at)->format('M j, Y') : '—' }}
                                        </p>
                                    </div>
                                </div>
                                <span class="cc-chip cc-chip-info shrink-0">{{ $rel->relationshipLabel() }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($awaitingVisitors > 0)
                    <p class="mt-sm text-metadata text-text-secondary">
                        {{ $awaitingVisitors }} more {{ \Illuminate\Support\Str::plural('visitor', $awaitingVisitors) }} linked to this PDL {{ $awaitingVisitors === 1 ? 'is' : 'are' }} still awaiting verification.
                    </p>
                @endif
            </section>
        </div>
    </div>
@endsection
