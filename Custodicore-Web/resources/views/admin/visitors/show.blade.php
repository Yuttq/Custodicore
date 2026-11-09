@extends('layouts.admin')

@section('title', 'Visitor Profile')
@section('heading', '')

@php
    $status = $visitor->verification_status;
    // Account on/off (accounts.status) — separate from verification above.
    $accountStatus = $visitor->account?->status;
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
        <a href="{{ route('admin.visitors.index') }}" class="cc-btn-secondary">Back</a>
    </div>

    {{-- One profile card: banner on top, quick facts left, details/history in the middle, summary right. --}}
    <div class="overflow-hidden rounded-card border border-border bg-card shadow-card">
        <div class="bg-primary-navy px-lg py-md">
            <p class="text-page-title text-white">{{ $visitor->full_name }}</p>
            <p class="text-metadata text-white/70">
                {{ $visitor->account?->email ?? 'No email on file' }}
            </p>
        </div>

        <div class="grid grid-cols-1 gap-md p-md lg:grid-cols-[250px_minmax(0,1fr)_280px]">

            {{-- ===== LEFT: avatar + quick facts ===== --}}
            <div class="space-y-md">
                {{-- The visitor's own photo (taken in the mobile app), served from private
                     storage through the staff-only visitor.photo route. Silhouette when none yet,
                     or if the picture fails to load. --}}
                @php $photoUrl = $visitor->photoUrl(); @endphp
                <div class="aspect-[4/5] w-full overflow-hidden rounded-sm border border-border bg-background">
                    @if ($photoUrl)
                        <a href="{{ $photoUrl }}" target="_blank" rel="noopener" title="Open full-size photo" style="display:block; width:100%; height:100%;">
                            <img src="{{ $photoUrl }}" alt="Photo of {{ $visitor->full_name }}"
                                 style="display:block; width:100%; height:100%; object-fit:cover;"
                                 onerror="this.parentElement.style.display='none'; this.parentElement.nextElementSibling.style.display='block';">
                        </a>
                        <div style="display:none; width:100%; height:100%;">
                            @include('admin.partials.person-silhouette')
                        </div>
                    @else
                        @include('admin.partials.person-silhouette')
                    @endif
                </div>
                @if ($photoUrl)
                    <p class="text-center text-metadata text-text-secondary" style="margin-top:-8px;">
                        Photo taken in the app{{ $visitor->photo_uploaded_at ? ' · ' . $visitor->photo_uploaded_at->format('M j, Y') : '' }}
                    </p>
                @else
                    <p class="text-center text-metadata text-text-secondary" style="margin-top:-8px;">No photo uploaded yet</p>
                @endif

                <div class="rounded-sm border border-primary-navy/10 bg-primary-navy/[0.07] p-md text-center text-metadata italic text-text-secondary">
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

                {{-- Account access: switches accounts.status only. Verification is not touched. --}}
                @if ($accountStatus)
                    <div class="space-y-sm rounded-sm border border-border p-md" id="account-access">
                        <div class="flex items-center justify-between gap-sm">
                            <p class="text-card-title text-primary-navy">Account access</p>
                            @include('admin.partials.status-chip', ['status' => $accountStatus])
                        </div>

                        @if ($accountStatus === 'active')
                            <p class="text-metadata text-text-secondary">
                                Deactivating signs them out of the app and blocks sign-in and check-in. Verification stays as it is.
                                @if ($upcomingVisits > 0)
                                    <strong class="text-warning">{{ $upcomingVisits }} upcoming {{ \Illuminate\Support\Str::plural('visit', $upcomingVisits) }}</strong> will stay booked but be refused at the gate.
                                @endif
                            </p>
                            <form method="POST" action="{{ route('admin.visitors.account-status', $visitor->visitor_id) }}"
                                  class="space-y-sm"
                                  data-password-confirm="Deactivate {{ $visitor->full_name }}'s account? Enter your password to confirm.">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="action" value="deactivate">
                                <label for="deactivate-reason" class="cc-label">Reason (required)</label>
                                <textarea id="deactivate-reason" name="reason" rows="2" maxlength="200" required
                                          class="cc-input h-auto py-sm" placeholder="e.g. Banned by facility order">{{ old('reason') }}</textarea>
                                @error('reason')
                                    <p class="text-metadata text-danger">{{ $message }}</p>
                                @enderror
                                <button type="submit" class="cc-btn-secondary w-full text-danger">Deactivate account</button>
                            </form>
                        @else
                            <p class="text-metadata text-text-secondary">
                                They cannot sign in to the app or be checked in. Reactivating lets them sign in again with their verification unchanged.
                            </p>
                            <form method="POST" action="{{ route('admin.visitors.account-status', $visitor->visitor_id) }}"
                                  data-password-confirm="Reactivate {{ $visitor->full_name }}'s account? Enter your password to confirm.">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="action" value="reactivate">
                                <button type="submit" class="cc-btn-primary w-full">Reactivate account</button>
                            </form>
                        @endif
                    </div>
                @endif
            </div>

            {{-- ===== MIDDLE: chips, more details, relationships, history, flags ===== --}}
            <div class="min-w-0 space-y-lg">
                <div class="flex flex-wrap gap-sm">
                    @include('admin.partials.status-chip', ['status' => $status])
                    @if ($accountStatus && $accountStatus !== 'active')
                        <span class="cc-chip cc-chip-danger">Account {{ $accountStatus === 'inactive' ? 'deactivated' : $accountStatus }}</span>
                    @endif
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
            <div class="space-y-lg rounded-sm border border-primary-navy/10 bg-primary-navy/[0.08] p-md">
                @include('admin.partials.bar-list', ['title' => 'Visits by Status', 'rows' => $visitsByStatus, 'empty' => 'No visits yet.'])
                @include('admin.partials.bar-list', ['title' => 'PDL Links by Status', 'rows' => $linkStatuses, 'empty' => 'No linked PDLs yet.'])
                @include('admin.partials.bar-list', ['title' => 'Flags', 'rows' => $flagBars, 'empty' => 'No flags. Clean record.'])
            </div>
        </div>
    </div>
@endsection
