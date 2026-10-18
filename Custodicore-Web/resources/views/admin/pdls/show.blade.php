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

    $daysInCustody = $pdl->admission_date
        ? abs((int) $pdl->admission_date->copy()->startOfDay()->diffInDays(now()->startOfDay()))
        : null;

    // Right-hand bars: real counts only.
    $caseStatuses = $pdl->legalRecords
        ->groupBy(fn ($r) => $r->case_status ? ucfirst($r->case_status) : 'No status')
        ->map->count()->sortDesc()->all();
    $restricted = $pdl->disciplinaryRecords->where('triggers_restriction', true)->count();
    $disciplinary = $pdl->disciplinaryRecords->count()
        ? array_filter(['Led to a restriction' => $restricted, 'No restriction' => $pdl->disciplinaryRecords->count() - $restricted])
        : [];
@endphp

@section('content')
    <div>
        <a href="{{ route('admin.pdls.index') }}" class="cc-btn-secondary">Back to PDL Management</a>
    </div>

    {{-- One profile card: banner on top, quick facts left, records in the middle, summary right. --}}
    <div class="overflow-hidden rounded-card border border-border bg-card shadow-card">
        <div class="bg-primary-navy px-lg py-md">
            <p class="text-page-title text-white">{{ $pdl->full_name }}</p>
            <p class="text-metadata text-white/70">
                {{ $pdl->pdl_number }}@if ($pdl->alias) · alias "{{ $pdl->alias }}"@endif
                · Read-only view, records are managed by the Record Officer
            </p>
        </div>

        <div class="grid grid-cols-1 gap-md p-md lg:grid-cols-[250px_minmax(0,1fr)_280px]">

            {{-- ===== LEFT: photo + quick facts ===== --}}
            <div class="space-y-md">
                <div class="aspect-[4/5] w-full overflow-hidden rounded-sm border border-border">
                    @if ($photo)
                        <img src="{{ $photo }}" alt="Photo of {{ $pdl->full_name }}" class="h-full w-full object-cover">
                    @else
                        @include('admin.partials.person-silhouette')
                    @endif
                </div>

                <div class="rounded-sm bg-background p-md text-center text-metadata italic text-text-secondary">
                    {{ $pdl->alias ? '"' . $pdl->alias . '"' : 'No alias on record' }}
                </div>

                <dl class="space-y-sm rounded-sm border border-primary-navy/30 p-md text-body">
                    @foreach ([
                        'Age' => $pdl->date_of_birth ? $pdl->date_of_birth->age . ' years old' : '—',
                        'Gender' => $pdl->gender ? ucfirst($pdl->gender) : '—',
                        'Born' => $pdl->date_of_birth?->format('M j, Y') ?? '—',
                        'Admitted' => $pdl->admission_date?->format('M j, Y') ?? '—',
                        'Cell / Block' => $pdl->cell_block ?: 'Not assigned',
                    ] as $label => $value)
                        <div class="flex justify-between gap-sm">
                            <dt class="text-text-secondary">{{ $label }}</dt>
                            <dd class="text-right font-semibold text-primary-navy">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            {{-- ===== MIDDLE: chips, name, legal + disciplinary records ===== --}}
            <div class="min-w-0 space-y-lg">
                <div class="flex flex-wrap gap-sm">
                    @include('admin.partials.status-chip', ['status' => $pdl->custody_status])
                    <span class="cc-chip cc-chip-info">{{ $classification }}</span>
                    <span class="cc-chip cc-chip-neutral">{{ $pdl->legalRecords->count() }} legal {{ \Illuminate\Support\Str::plural('record', $pdl->legalRecords->count()) }}</span>
                    <span class="cc-chip {{ $pdl->disciplinaryRecords->count() ? 'cc-chip-warning' : 'cc-chip-neutral' }}">{{ $pdl->disciplinaryRecords->count() }} disciplinary</span>
                </div>

                <div>
                    <p class="text-card-title text-primary-navy">Name</p>
                    <dl class="mt-sm grid grid-cols-2 gap-md sm:grid-cols-4">
                        @foreach (['First' => $nameParts['first'], 'Middle' => $nameParts['middle'], 'Last' => $nameParts['last'], 'Alias' => $pdl->alias] as $label => $value)
                            <div>
                                <dt class="text-section-label uppercase text-text-secondary">{{ $label }}</dt>
                                <dd class="mt-xs text-body font-semibold">{{ $value ?: '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

                <div>
                    <p class="text-card-title text-primary-navy">Legal Records <span class="cc-chip cc-chip-neutral ml-xs">{{ $pdl->legalRecords->count() }}</span></p>

                    @if ($pdl->legalRecords->isEmpty())
                        <p class="mt-sm text-body text-text-secondary">No legal records on file.</p>
                    @else
                        <div class="mt-sm space-y-sm">
                            @foreach ($pdl->legalRecords as $record)
                                <div class="rounded-sm border border-border p-md">
                                    <div class="flex flex-wrap items-center justify-between gap-sm">
                                        <p class="text-body font-semibold">{{ $record->case_number }}</p>
                                        @if ($record->case_status)
                                            <span class="cc-chip cc-chip-neutral">{{ ucfirst($record->case_status) }}</span>
                                        @endif
                                    </div>
                                    <p class="mt-xs text-body">{{ $record->offense }}</p>
                                    <p class="mt-xs text-metadata text-text-secondary">
                                        {{ $record->court ?: 'Court not recorded' }} · Recorded {{ $record->created_at?->format('M j, Y') ?? '—' }}
                                    </p>
                                    @if ($record->remarks)
                                        <p class="mt-xs text-metadata text-text-secondary">Remarks: {{ $record->remarks }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div>
                    <p class="text-card-title text-primary-navy">Disciplinary Records <span class="cc-chip cc-chip-neutral ml-xs">{{ $pdl->disciplinaryRecords->count() }}</span></p>

                    @if ($pdl->disciplinaryRecords->isEmpty())
                        <p class="mt-sm text-body text-text-secondary">No disciplinary records on file.</p>
                    @else
                        <div class="mt-sm space-y-sm">
                            @foreach ($pdl->disciplinaryRecords as $record)
                                <div class="rounded-sm border border-border p-md">
                                    <div class="flex flex-wrap items-center justify-between gap-sm">
                                        <p class="text-body font-semibold">{{ $record->incident_date?->format('M j, Y') ?? '—' }}</p>
                                        @if ($record->triggers_restriction)
                                            <span class="cc-chip cc-chip-danger">Led to restriction</span>
                                        @else
                                            <span class="cc-chip cc-chip-neutral">No restriction</span>
                                        @endif
                                    </div>
                                    <p class="mt-xs text-body">{{ $record->description }}</p>
                                    <p class="mt-xs text-metadata text-text-secondary">
                                        Action taken: {{ $record->action_taken ?: '—' }} · Recorded {{ $record->created_at?->format('M j, Y') ?? '—' }}
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- ===== RIGHT: at-a-glance panel ===== --}}
            <div class="space-y-lg rounded-sm bg-primary-navy/5 p-md">
                <div>
                    <p class="text-card-title text-primary-navy">In Custody</p>
                    @if ($daysInCustody !== null)
                        <p class="mt-sm text-stat-value text-primary-navy">{{ number_format($daysInCustody) }} <span class="text-metadata font-normal text-text-secondary">{{ $daysInCustody === 1 ? 'day' : 'days' }}</span></p>
                        <p class="text-metadata text-text-secondary">Since {{ $pdl->admission_date->format('M j, Y') }}</p>
                    @else
                        <p class="mt-sm text-metadata text-text-secondary">No admission date recorded.</p>
                    @endif
                </div>

                @include('admin.partials.bar-list', ['title' => 'Legal Records by Status', 'rows' => $caseStatuses, 'empty' => 'No legal records yet.'])
                @include('admin.partials.bar-list', ['title' => 'Disciplinary Outcomes', 'rows' => $disciplinary, 'empty' => 'No disciplinary records. Clean conduct record.'])
            </div>
        </div>
    </div>
@endsection
