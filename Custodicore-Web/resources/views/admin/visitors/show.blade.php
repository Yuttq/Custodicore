@extends('layouts.admin')

@section('title', $visitor->full_name)

@php
    $status = $visitor->verification_status;
    $plural = fn (int $n, string $word) => $n . ' ' . \Illuminate\Support\Str::plural($word, $n);

    // Right-hand bars: real counts only.
    $label = fn ($value) => ucwords(str_replace('_', ' ', (string) $value));
    $visitsByStatus = $visitor->visitRequests->groupBy('status')->map->count()->sortDesc()
        ->mapWithKeys(fn ($n, $s) => [$label($s) => $n])->all();
    $linkStatuses = $visitor->relationships->groupBy('verification_status')->map->count()->sortDesc()
        ->mapWithKeys(fn ($n, $s) => [$label($s) => $n])->all();
    $resolvedFlags = $visitor->flags->count() - $activeFlags;
    $flagBars = array_filter(['Active' => $activeFlags, 'Resolved' => $resolvedFlags]);

    $more = [
        'Address' => $visitor->address ?: '—',
        'Emergency Contact' => $visitor->emergency_contact_name
            ? $visitor->emergency_contact_name . ($visitor->emergency_contact_number ? ' (' . $visitor->emergency_contact_number . ')' : '')
            : '—',
        'Verified On' => $visitor->verified_at?->format('M j, Y') ?? '—',
    ] + ($visitor->hasGuardianDetails() ? [
        'Guardian Full Name' => $visitor->guardian_full_name ?: '—',
        "Guardian's Relationship to Visitor" => $visitor->guardian_relationship ?: '—',
        'Guardian Contact Number' => $visitor->guardian_contact_number ?: '—',
    ] : []);
@endphp

@section('content')
    <div>
        <a href="{{ route('admin.visitors.index') }}" class="cc-btn-secondary">Back to Visitor Management</a>
    </div>

    {{-- One profile card: banner on top, quick facts left, details/history in the middle, summary right. --}}
    <div class="overflow-hidden rounded-card border border-border bg-card shadow-card">
        <div class="bg-primary-navy px-lg py-md">
            <p class="text-page-title text-white">{{ $visitor->full_name }}</p>
            <p class="text-metadata text-white/70">
                {{ $visitor->account?->email ?? 'No email on file' }}
                · Read-only view, verification is done by the Record Officer
            </p>
        </div>

        <div class="grid grid-cols-1 gap-md p-md lg:grid-cols-[250px_minmax(0,1fr)_280px]">

            {{-- ===== LEFT: avatar + quick facts ===== --}}
            <div class="space-y-md">
                <div class="aspect-[4/5] w-full overflow-hidden rounded-sm border border-border">
                    @include('admin.partials.person-silhouette')
                </div>

                <div class="rounded-sm bg-background p-md text-center text-metadata italic text-text-secondary">
                    @if ($visitor->relationship_hint)
                        Said at sign-up: "{{ $visitor->relationship_hint }}"
                    @else
                        No relationship stated at sign-up
                    @endif
                </div>

                <dl class="space-y-sm rounded-sm border border-primary-navy/30 p-md text-body">
                    @foreach ([
                        'Age' => $visitor->date_of_birth ? $visitor->date_of_birth->age . ' years old' : '—',
                        'Gender' => $visitor->gender ? ucfirst($visitor->gender) : 'Prefer not to say',
                        'Born' => $visitor->date_of_birth?->format('M j, Y') ?? '—',
                        'Contact' => $visitor->contact_number ?: '—',
                    ] as $name => $value)
                        <div class="flex justify-between gap-sm">
                            <dt class="text-text-secondary">{{ $name }}</dt>
                            <dd class="text-right font-semibold text-primary-navy">{{ $value }}</dd>
                        </div>
                    @endforeach
                    <div>
                        <dt class="text-text-secondary">Email</dt>
                        <dd class="break-all font-semibold text-primary-navy">{{ $visitor->account?->email ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            {{-- ===== MIDDLE: chips, more details, relationships, history, flags ===== --}}
            <div class="min-w-0 space-y-lg">
                <div class="flex flex-wrap gap-sm">
                    @include('admin.partials.status-chip', ['status' => $status])
                    @if ($activeFlags > 0)
                        <span class="cc-chip cc-chip-danger">{{ $plural($activeFlags, 'active flag') }}</span>
                    @endif
                    <span class="cc-chip cc-chip-neutral">{{ $plural($visitor->relationships->count(), 'linked PDL') }}</span>
                    <span class="cc-chip cc-chip-neutral">{{ $plural($visitor->visitRequests->count(), 'visit') }}</span>
                </div>

                <div>
                    <p class="text-card-title text-primary-navy">Profile Details</p>
                    <dl class="mt-sm grid grid-cols-1 gap-md sm:grid-cols-2">
                        @foreach ($more as $name => $value)
                            <div>
                                <dt class="text-section-label uppercase text-text-secondary">{{ $name }}</dt>
                                <dd class="mt-xs text-body font-semibold">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    @if ($status === 'rejected' && $visitor->rejection_reason)
                        <p class="mt-md text-body text-danger">Returned by staff: {{ $visitor->rejection_reason }}</p>
                    @endif
                </div>

                <div>
                    <p class="text-card-title text-primary-navy">Relationship to PDLs <span class="cc-chip cc-chip-neutral ml-xs">{{ $visitor->relationships->count() }}</span></p>

                    @if ($visitor->relationships->isEmpty())
                        <p class="mt-sm text-body text-text-secondary">No PDL relationship registered for this visitor yet.</p>
                    @else
                        <div class="mt-sm space-y-sm">
                            @foreach ($visitor->relationships as $rel)
                                <div class="flex flex-wrap items-center justify-between gap-sm rounded-sm border border-border p-md">
                                    <div>
                                        @if ($rel->pdl)
                                            <a href="{{ route('admin.pdls.show', $rel->pdl->pdl_id) }}" class="text-body font-semibold hover:underline">{{ $rel->pdl->full_name }}</a>
                                            <span class="text-metadata text-text-secondary">· {{ $rel->pdl->pdl_number }}</span>
                                        @else
                                            <span class="text-body text-text-secondary">PDL not found</span>
                                        @endif
                                        <p class="text-metadata text-text-secondary">{{ $rel->relationshipLabel() }}</p>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-sm">
                                        @if ($rel->isHighPriority())
                                            <span class="cc-chip cc-chip-info">High priority</span>
                                        @else
                                            <span class="cc-chip cc-chip-neutral">Requires verification</span>
                                        @endif
                                        @include('admin.partials.status-chip', ['status' => $rel->verification_status])
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div>
                    <p class="text-card-title text-primary-navy">Visit History <span class="cc-chip cc-chip-neutral ml-xs">{{ $visitor->visitRequests->count() }}</span></p>
                    <p class="text-metadata text-text-secondary">Most recent 50 visits.</p>

                    @if ($visitor->visitRequests->isEmpty())
                        <p class="mt-sm text-body text-text-secondary">No visits yet.</p>
                    @else
                        <div class="mt-sm overflow-x-auto">
                            <table class="w-full text-left text-body">
                                <thead>
                                    <tr class="border-b border-border text-section-label text-text-secondary">
                                        <th class="py-sm pr-md">Date</th>
                                        <th class="py-sm pr-md">Time</th>
                                        <th class="py-sm pr-md">PDL Visited</th>
                                        <th class="py-sm pr-md">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @foreach ($visitor->visitRequests as $visit)
                                        @php
                                            $slots = $visit->sessionSchedules->isNotEmpty() ? $visit->sessionSchedules : collect([$visit->schedule])->filter();
                                        @endphp
                                        <tr>
                                            <td class="py-sm pr-md font-semibold">{{ $visit->schedule?->schedule_date?->format('M j, Y') ?? '—' }}</td>
                                            <td class="py-sm pr-md text-text-secondary">
                                                {{ $slots->isEmpty() ? '—' : $slots->map(fn ($s) => substr($s->time_slot_start, 0, 5) . '–' . substr($s->time_slot_end, 0, 5))->implode(' and ') }}
                                            </td>
                                            <td class="py-sm pr-md">{{ $visit->pdl?->full_name ?? '—' }}</td>
                                            <td class="py-sm pr-md">@include('admin.partials.status-chip', ['status' => $visit->status])</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div>
                    <p class="text-card-title text-primary-navy">Flags <span class="cc-chip cc-chip-neutral ml-xs">{{ $visitor->flags->count() }}</span></p>

                    @if ($visitor->flags->isEmpty())
                        <p class="mt-sm text-body text-text-secondary">No flags on file. Clean record.</p>
                    @else
                        <div class="mt-sm space-y-sm">
                            @foreach ($visitor->flags as $flag)
                                <div class="rounded-sm border border-border p-md">
                                    <div class="flex flex-wrap items-center justify-between gap-sm">
                                        <p class="text-body font-semibold">{{ $flag->typeLabel() }}</p>
                                        @if ($flag->isActive())
                                            <span class="cc-chip cc-chip-danger">Active</span>
                                        @else
                                            <span class="cc-chip cc-chip-success">Resolved</span>
                                        @endif
                                    </div>
                                    @if ($flag->description)
                                        <p class="mt-xs text-body">{{ $flag->description }}</p>
                                    @endif
                                    <p class="mt-xs text-metadata text-text-secondary">
                                        Flagged by {{ $flag->flaggedBy?->full_name ?? '—' }} on {{ $flag->created_at?->format('M j, Y') ?? '—' }}
                                        @if ($flag->resolved_at)
                                            · Resolved {{ $flag->resolved_at->format('M j, Y') }}@if ($flag->resolvedBy) by {{ $flag->resolvedBy->full_name }}@endif
                                        @endif
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- ===== RIGHT: at-a-glance panel ===== --}}
            <div class="space-y-lg rounded-sm bg-primary-navy/5 p-md">
                @include('admin.partials.bar-list', ['title' => 'Visits by Status', 'rows' => $visitsByStatus, 'empty' => 'No visits yet.'])
                @include('admin.partials.bar-list', ['title' => 'PDL Links by Status', 'rows' => $linkStatuses, 'empty' => 'No linked PDLs yet.'])
                @include('admin.partials.bar-list', ['title' => 'Flags', 'rows' => $flagBars, 'empty' => 'No flags. Clean record.'])
            </div>
        </div>
    </div>
@endsection
