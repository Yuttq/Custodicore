@extends('layouts.admin')

@section('title', 'Visitor Profile')
@section('heading', '')

@php
    $status = $visitor->verification_status;
    // Account on/off (accounts.status) — separate from verification above.
    $accountStatus = $visitor->account?->status;
    $photoUrl = $visitor->photoUrl();

    $details = [
        'Age' => $visitor->date_of_birth
            ? $visitor->date_of_birth->age . ' · ' . ($visitor->gender ? ucfirst($visitor->gender) : 'Gender not stated')
            : ($visitor->gender ? ucfirst($visitor->gender) : '—'),
        'Born' => $visitor->date_of_birth?->format('M j, Y') ?? '—',
        'Contact' => $visitor->contact_number ?: '—',
        'Address' => $visitor->address ?: '—',
    ];
    if ($visitor->emergency_contact_name) {
        $details['Emergency'] = $visitor->emergency_contact_name
            . ($visitor->emergency_contact_number ? ' (' . $visitor->emergency_contact_number . ')' : '');
    }
    if ($visitor->verified_at) {
        $details['Verified'] = $visitor->verified_at->format('M j, Y');
    }
    $guardian = $visitor->hasGuardianDetails() ? [
        'Guardian Full Name' => $visitor->guardian_full_name ?: '—',
        "Guardian's Relationship to Visitor" => $visitor->guardian_relationship ?: '—',
        'Guardian Contact Number' => $visitor->guardian_contact_number ?: '—',
    ] : [];

    // Reopen the Disable popup after a missing reason (a wrong password is
    // explained by the password-confirm banner instead).
    $reopenDisable = $errors->has('reason');
@endphp

@section('content')
    <div>
        <a href="{{ route('admin.visitors.index') }}" class="cc-btn-secondary">Back</a>
    </div>

    {{-- One profile card: header with photo, name and status; details left; PDLs, visits and flags right. --}}
    <div class="overflow-hidden rounded-card border border-border bg-card shadow-card">
        <div class="flex items-center gap-md bg-primary-navy px-md py-md sm:px-lg">
            {{-- The visitor's own photo (taken in the mobile app), served from private storage
                 through the staff-only visitor.photo route. Click to open it full size. --}}
            <div class="h-14 w-14 shrink-0 overflow-hidden rounded-full border-2 border-white/40 bg-white/10">
                @if ($photoUrl)
                    <a href="{{ $photoUrl }}" target="_blank" rel="noopener"
                       title="Open full-size photo{{ $visitor->photo_uploaded_at ? ' (taken ' . $visitor->photo_uploaded_at->format('M j, Y') . ')' : '' }}"
                       style="display:block; width:100%; height:100%;">
                        <img src="{{ $photoUrl }}" alt="Photo of {{ $visitor->full_name }}"
                             style="display:block; width:100%; height:100%; object-fit:cover;"
                             onerror="this.parentElement.style.display='none'; this.parentElement.nextElementSibling.style.display='block';">
                    </a>
                    <div style="display:none; width:100%; height:100%;">
                        @include('admin.partials.person-silhouette')
                    </div>
                @else
                    <div title="No photo uploaded yet" style="width:100%; height:100%;">
                        @include('admin.partials.person-silhouette')
                    </div>
                @endif
            </div>

            <div class="min-w-0 flex-1">
                <p class="break-words text-card-title text-white">{{ $visitor->full_name }}</p>
                <p class="break-all text-metadata text-white/70">{{ $visitor->account?->email ?? 'No email on file' }}</p>

                <div class="mt-sm flex flex-wrap items-center gap-xs">
                    @include('admin.partials.status-chip', ['status' => $status])
                    @if ($accountStatus === 'active')
                        @include('admin.partials.status-chip', ['status' => 'active'])
                    @elseif ($accountStatus)
                        <span class="cc-chip cc-chip-danger">Account {{ $accountStatus === 'inactive' ? 'deactivated' : $accountStatus }}</span>
                    @endif
                    @if ($activeFlags > 0)
                        <span class="cc-chip cc-chip-danger">{{ $activeFlags }} active {{ \Illuminate\Support\Str::plural('flag', $activeFlags) }}</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-md p-md lg:grid-cols-[260px_minmax(0,1fr)]">

            {{-- ===== LEFT: details + account access ===== --}}
            <div class="space-y-md">
                <div class="rounded-sm border border-border p-md">
                    <p class="text-section-label uppercase text-text-secondary">Details</p>
                    <dl class="mt-sm divide-y divide-border text-metadata">
                        @foreach ($details as $name => $value)
                            <div class="flex justify-between gap-sm py-xs">
                                <dt class="text-text-secondary">{{ $name }}</dt>
                                <dd class="text-right font-semibold">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    @if ($guardian)
                        <p class="mt-md text-section-label uppercase text-text-secondary">Guardian</p>
                        <dl class="mt-xs divide-y divide-border text-metadata">
                            @foreach ($guardian as $name => $value)
                                <div class="py-xs">
                                    <dt class="text-text-secondary">{{ $name }}</dt>
                                    <dd class="font-semibold">{{ $value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif

                    @if ($status === 'rejected' && $visitor->rejection_reason)
                        <p class="mt-sm text-metadata text-danger">Returned by staff: {{ $visitor->rejection_reason }}</p>
                    @endif
                </div>

                {{-- Account access: switches accounts.status only. Verification is not touched. --}}
                @if ($accountStatus === 'active')
                    <button type="button" class="cc-btn-secondary w-full text-danger"
                            onclick="document.getElementById('disable-account-modal').showModal()">
                        Disable Account
                    </button>
                @elseif ($accountStatus)
                    <div class="space-y-sm rounded-sm border border-border p-md" id="account-access">
                        <p class="text-metadata text-text-secondary">
                            They cannot sign in to the app or be checked in. Reactivating keeps their verification as it is.
                        </p>
                        <form method="POST" action="{{ route('admin.visitors.account-status', $visitor->visitor_id) }}"
                              data-password-confirm="Reactivate {{ $visitor->full_name }}'s account? Enter your password to confirm.">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="action" value="reactivate">
                            <button type="submit" class="cc-btn-primary w-full">Enable Account</button>
                        </form>
                    </div>
                @endif
            </div>

            {{-- ===== MAIN: linked PDLs, visit history, flags ===== --}}
            <div class="min-w-0 space-y-md">
                <div>
                    <p class="text-section-label uppercase text-text-secondary">Linked PDLs ({{ $visitor->relationships->count() }})</p>

                    @if ($visitor->relationships->isEmpty())
                        <p class="mt-xs text-metadata text-text-secondary">No PDL relationship registered for this visitor yet.</p>
                    @else
                        <div class="mt-xs divide-y divide-border rounded-sm border border-border">
                            @foreach ($visitor->relationships as $rel)
                                <div class="flex flex-wrap items-center justify-between gap-sm px-md py-sm">
                                    <div class="text-metadata">
                                        @if ($rel->pdl)
                                            <a href="{{ route('admin.pdls.show', $rel->pdl->pdl_id) }}" class="font-semibold text-text-primary hover:underline">{{ $rel->pdl->full_name }}</a>
                                            <span class="text-text-secondary">· {{ $rel->pdl->pdl_number }} · {{ $rel->relationshipLabel() }}</span>
                                        @else
                                            <span class="text-text-secondary">PDL not found · {{ $rel->relationshipLabel() }}</span>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap items-center gap-xs">
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
                    <p class="text-section-label uppercase text-text-secondary">
                        Visit History ({{ $visitor->visitRequests->count() }}{{ $visitor->visitRequests->count() >= 50 ? ', latest 50' : '' }})
                    </p>

                    @if ($visitor->visitRequests->isEmpty())
                        <p class="mt-xs text-metadata text-text-secondary">No visits yet.</p>
                    @else
                        <div class="mt-xs overflow-x-auto rounded-sm border border-border">
                            <table class="w-full text-left text-metadata">
                                <thead>
                                    <tr class="border-b border-border bg-background text-text-secondary">
                                        <th class="px-md py-xs font-semibold">Date</th>
                                        <th class="px-md py-xs font-semibold">Time</th>
                                        <th class="px-md py-xs font-semibold">PDL Visited</th>
                                        <th class="px-md py-xs font-semibold">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @foreach ($visitor->visitRequests as $visit)
                                        @php
                                            $slots = $visit->sessionSchedules->isNotEmpty() ? $visit->sessionSchedules : collect([$visit->schedule])->filter();
                                        @endphp
                                        <tr>
                                            <td class="whitespace-nowrap px-md py-xs font-semibold">{{ $visit->schedule?->schedule_date?->format('M j, Y') ?? '—' }}</td>
                                            <td class="px-md py-xs text-text-secondary">
                                                {{ $slots->isEmpty() ? '—' : $slots->map(fn ($s) => substr($s->time_slot_start, 0, 5) . '–' . substr($s->time_slot_end, 0, 5))->implode(', ') }}
                                            </td>
                                            <td class="px-md py-xs">{{ $visit->pdl?->full_name ?? '—' }}</td>
                                            <td class="px-md py-xs">@include('admin.partials.status-chip', ['status' => $visit->status])</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div>
                    @if ($visitor->flags->isEmpty())
                        <p class="text-metadata text-text-secondary">
                            <span class="text-section-label uppercase">Flags</span> · None — clean record.
                        </p>
                    @else
                        <p class="text-section-label uppercase text-text-secondary">Flags ({{ $visitor->flags->count() }})</p>
                        <div class="mt-xs divide-y divide-border rounded-sm border border-border">
                            @foreach ($visitor->flags as $flag)
                                <div class="px-md py-sm text-metadata">
                                    <div class="flex flex-wrap items-center justify-between gap-sm">
                                        <p class="font-semibold">{{ $flag->typeLabel() }}</p>
                                        @if ($flag->isActive())
                                            <span class="cc-chip cc-chip-danger">Active</span>
                                        @else
                                            <span class="cc-chip cc-chip-success">Resolved</span>
                                        @endif
                                    </div>
                                    @if ($flag->description)
                                        <p class="mt-xs">{{ $flag->description }}</p>
                                    @endif
                                    <p class="mt-xs text-text-secondary">
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
        </div>
    </div>

    {{-- Disable Account: reason + the admin's own password (data-password-confirm → partials/password-confirm). --}}
    @if ($accountStatus === 'active')
        <dialog id="disable-account-modal" class="cc-modal">
            <form method="POST" action="{{ route('admin.visitors.account-status', $visitor->visitor_id) }}"
                  class="space-y-sm"
                  data-password-confirm="Deactivate {{ $visitor->full_name }}'s account? Enter your password to confirm.">
                @csrf
                @method('PATCH')
                <input type="hidden" name="action" value="deactivate">

                <div class="flex items-center justify-between">
                    <p class="text-card-title">Disable {{ $visitor->full_name }}'s account?</p>
                    <button type="button" class="cc-modal-close" onclick="document.getElementById('disable-account-modal').close()">Close</button>
                </div>

                <p class="text-metadata text-text-secondary">
                    They are signed out of the app and cannot sign in or be checked in. Verification stays as it is.
                    @if ($upcomingVisits > 0)
                        <strong class="text-warning">{{ $upcomingVisits }} upcoming {{ \Illuminate\Support\Str::plural('visit', $upcomingVisits) }}</strong> will stay booked but be refused at the gate.
                    @endif
                </p>

                <label for="deactivate-reason" class="cc-label">Reason (required)</label>
                <textarea id="deactivate-reason" name="reason" rows="2" maxlength="200" required
                          class="cc-input h-auto py-sm" placeholder="e.g. Banned by facility order">{{ old('reason') }}</textarea>
                @error('reason')
                    <p class="text-metadata text-danger">{{ $message }}</p>
                @enderror

                <div class="flex justify-end gap-sm pt-sm">
                    <button type="button" class="cc-btn-secondary" onclick="document.getElementById('disable-account-modal').close()">Cancel</button>
                    <button type="submit" class="cc-btn-secondary text-danger">Disable Account</button>
                </div>
            </form>
        </dialog>

        @if ($reopenDisable)
            <script>document.getElementById('disable-account-modal').showModal();</script>
        @endif
    @endif
@endsection
