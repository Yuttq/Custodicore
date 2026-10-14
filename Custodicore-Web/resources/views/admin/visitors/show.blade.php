@extends('layouts.admin')

@section('title', $visitor->full_name)

@php
    $status = $visitor->verification_status;
@endphp

@section('content')
    <div>
        <a href="{{ route('admin.visitors.index') }}" class="cc-btn-secondary">Back to Visitor Management</a>
    </div>

    {{-- ================= SUMMARY ================= --}}
    <div class="cc-card">
        <div class="flex flex-wrap items-center gap-md">
            @include('admin.partials.avatar', ['name' => $visitor->full_name, 'size' => 'h-20 w-20'])
            <div>
                <p class="text-page-title">{{ $visitor->full_name }}</p>
                <p class="text-metadata text-text-secondary">{{ $visitor->account?->email ?? 'No email on file' }}</p>
                <div class="mt-sm flex flex-wrap items-center gap-sm">
                    @include('admin.partials.status-chip', ['status' => $status])
                    @if ($activeFlags > 0)
                        <span class="cc-chip cc-chip-danger">{{ $activeFlags }} active {{ \Illuminate\Support\Str::plural('flag', $activeFlags) }}</span>
                    @endif
                </div>
            </div>
            <p class="ml-auto text-metadata text-text-secondary">Read-only view. Verification is done by the Record Officer.</p>
        </div>
    </div>

    {{-- ================= PROFILE ================= --}}
    <div class="cc-card">
        <p class="mb-md text-card-title">Profile</p>
        <dl class="grid grid-cols-1 gap-md sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                'Full Name' => $visitor->full_name,
                'Date of Birth' => $visitor->date_of_birth?->format('M j, Y') ?? '—',
                'Age' => $visitor->date_of_birth ? $visitor->date_of_birth->age . ' years old' : '—',
                'Gender' => $visitor->gender ? ucfirst($visitor->gender) : 'Prefer not to say',
                'Contact Number' => $visitor->contact_number ?: '—',
                'Email' => $visitor->account?->email ?? '—',
                'Address' => $visitor->address ?: '—',
                'Emergency Contact' => $visitor->emergency_contact_name
                    ? $visitor->emergency_contact_name . ($visitor->emergency_contact_number ? ' (' . $visitor->emergency_contact_number . ')' : '')
                    : '—',
                'Relationship Stated at Sign-up' => $visitor->relationship_hint ?: '—',
                'Verified On' => $visitor->verified_at?->format('M j, Y') ?? '—',
            ] as $label => $value)
                <div>
                    <dt class="text-section-label uppercase text-text-secondary">{{ $label }}</dt>
                    <dd class="mt-xs text-body font-semibold">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
        @if ($status === 'rejected' && $visitor->rejection_reason)
            <p class="mt-md text-body text-danger">Returned by staff: {{ $visitor->rejection_reason }}</p>
        @endif
    </div>

    {{-- ================= RELATIONSHIP TO PDLS ================= --}}
    <div class="cc-card">
        <p class="text-card-title">Relationship to PDLs <span class="cc-chip cc-chip-neutral ml-xs">{{ $visitor->relationships->count() }}</span></p>

        @if ($visitor->relationships->isEmpty())
            <p class="mt-md text-body text-text-secondary">No PDL relationship registered for this visitor yet.</p>
        @else
            <div class="mt-md overflow-x-auto">
                <table class="w-full text-left text-body">
                    <thead>
                        <tr class="border-b border-border text-section-label text-text-secondary">
                            <th class="py-sm pr-md">PDL</th>
                            <th class="py-sm pr-md">PDL #</th>
                            <th class="py-sm pr-md">Relationship</th>
                            <th class="py-sm pr-md">Priority</th>
                            <th class="py-sm pr-md">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($visitor->relationships as $rel)
                            <tr>
                                <td class="py-sm pr-md">
                                    @if ($rel->pdl)
                                        <a href="{{ route('admin.pdls.show', $rel->pdl->pdl_id) }}" class="font-semibold hover:underline">{{ $rel->pdl->full_name }}</a>
                                    @else
                                        <span class="text-text-secondary">PDL not found</span>
                                    @endif
                                </td>
                                <td class="py-sm pr-md text-text-secondary">{{ $rel->pdl?->pdl_number ?? '—' }}</td>
                                <td class="py-sm pr-md">{{ $rel->relationshipLabel() }}</td>
                                <td class="py-sm pr-md">
                                    @if ($rel->isHighPriority())
                                        <span class="cc-chip cc-chip-info">High priority</span>
                                    @else
                                        <span class="cc-chip cc-chip-neutral">Requires verification</span>
                                    @endif
                                </td>
                                <td class="py-sm pr-md">@include('admin.partials.status-chip', ['status' => $rel->verification_status])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ================= VISIT HISTORY ================= --}}
    <div class="cc-card">
        <p class="text-card-title">Visit History <span class="cc-chip cc-chip-neutral ml-xs">{{ $visitor->visitRequests->count() }}</span></p>
        <p class="text-metadata text-text-secondary">Most recent 50 visits.</p>

        @if ($visitor->visitRequests->isEmpty())
            <p class="mt-md text-body text-text-secondary">No visits yet.</p>
        @else
            <div class="mt-md overflow-x-auto">
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

    {{-- ================= FLAGS ================= --}}
    <div class="cc-card">
        <p class="text-card-title">Flags <span class="cc-chip cc-chip-neutral ml-xs">{{ $visitor->flags->count() }}</span></p>

        @if ($visitor->flags->isEmpty())
            <p class="mt-md text-body text-text-secondary">No flags on file. Clean record.</p>
        @else
            <div class="mt-md overflow-x-auto">
                <table class="w-full text-left text-body">
                    <thead>
                        <tr class="border-b border-border text-section-label text-text-secondary">
                            <th class="py-sm pr-md">Type</th>
                            <th class="py-sm pr-md">Description</th>
                            <th class="py-sm pr-md">Status</th>
                            <th class="py-sm pr-md">Flagged By</th>
                            <th class="py-sm pr-md">Date</th>
                            <th class="py-sm pr-md">Resolved</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($visitor->flags as $flag)
                            <tr>
                                <td class="py-sm pr-md font-semibold">{{ $flag->typeLabel() }}</td>
                                <td class="py-sm pr-md text-text-secondary">{{ $flag->description ?: '—' }}</td>
                                <td class="py-sm pr-md">
                                    @if ($flag->isActive())
                                        <span class="cc-chip cc-chip-danger">Active</span>
                                    @else
                                        <span class="cc-chip cc-chip-success">Resolved</span>
                                    @endif
                                </td>
                                <td class="py-sm pr-md text-text-secondary">{{ $flag->flaggedBy?->full_name ?? '—' }}</td>
                                <td class="py-sm pr-md text-text-secondary">{{ $flag->created_at?->format('M j, Y') ?? '—' }}</td>
                                <td class="py-sm pr-md text-text-secondary">
                                    @if ($flag->resolved_at)
                                        {{ $flag->resolved_at->format('M j, Y') }}@if ($flag->resolvedBy) by {{ $flag->resolvedBy->full_name }}@endif
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
