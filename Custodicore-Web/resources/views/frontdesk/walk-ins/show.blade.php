@extends('layouts.frontdesk')

@section('title', 'Walk-in Visitor')

@section('header_actions')
    <a href="{{ route('frontdesk.walk-ins.index') }}" class="cc-btn-secondary">Back to Walk-in Visitors</a>
@endsection

@section('content')
    {{-- Warnings first, as on the visitor profile. --}}
    @if ($checks['flagged'])
        <div class="cc-card border-l-4 border-l-danger" role="alert">
            <p class="text-card-title text-danger">Active flag on this visitor</p>
            <p class="text-metadata text-text-secondary">This walk-in can't be approved until the flag is resolved.</p>
        </div>
    @endif

    {{-- Who they are: photo, name, status, then the details on record (as on the visitor profile). --}}
    <div class="cc-card">
        <div class="flex flex-wrap items-start justify-between gap-sm">
            <div class="flex items-center gap-md">
                @include('frontdesk.partials.visitor-photo', ['visitor' => $visitor])
                <div>
                    @php
                        $statusTone = ['success' => 'text-success', 'warning' => 'text-warning', 'danger' => 'text-danger', 'info' => 'text-info'][$status['tone']] ?? 'text-text-secondary';
                        $statusDot = ['success' => 'bg-success', 'warning' => 'bg-warning', 'danger' => 'bg-danger', 'info' => 'bg-info'][$status['tone']] ?? 'bg-text-secondary';
                    @endphp
                    <p class="flex flex-wrap items-center gap-x-md gap-y-xs">
                        <span class="text-[22px] font-bold leading-tight text-primary-navy">{{ $visitor->full_name }}</span>
                        <span class="inline-flex items-center gap-xs text-body font-semibold {{ $statusTone }}">
                            <span class="h-2.5 w-2.5 rounded-full {{ $statusDot }}" aria-hidden="true"></span>
                            {{ $status['label'] }}
                        </span>
                    </p>
                    <p class="text-metadata text-text-secondary">
                        Visitor ID <strong>{{ $visitor->visitor_id }}</strong> · Walk-in · Registered {{ $visitor->created_at?->format('M d, Y') ?? '—' }}
                    </p>
                </div>
            </div>
        </div>


        {{-- What is happening now: shown as a short message (once per status), not a box. --}}
        <template id="walkInStatusMessage">
            @switch($status['key'])
                @case('waiting')
                    Not approved yet. Check the original ID and documents, then approve or reject the walk-in in the Walk-in Assessment below.
                    @break
                @case('returned')
                    This registration was rejected.
                    <strong>Reason:</strong> {{ $visitor->rejection_reason ?: 'No reason given.' }}
                    Explain it to the visitor; they can come back with what's missing.
                    @break
                @case('declined')
                    This walk-in was rejected. The decision is final; see the remarks in the assessment below and explain them to the visitor.
                    @break
                @case('assess')
                    The ID and PDL relationship are verified. Approve or reject this arrival in the Walk-in Assessment below.
                    @break
                @case('linking')
                    The ID is approved, but the PDL relationship is not verified yet. Approve or reject the walk-in in the Walk-in Assessment below.
                    @break
                @case('ready')
                    Approved, but no visit was booked. Record a new arrival with a date and session.
                    @break
                @case('booked')
                    @php
                        $bookedTimes = $status['visit']->sessionSchedules->map(fn ($s) => substr($s->time_slot_start, 0, 5).'–'.substr($s->time_slot_end, 0, 5))->implode(' · ');
                        $bookedToday = $status['visit']->schedule?->schedule_date?->isToday();
                    @endphp
                    @if ($bookedToday)
                        Approved. Verify the visitor's identity and check them in for
                        <strong>{{ $bookedTimes }}</strong>
                        with {{ $status['visit']->pdl?->full_name }}: take their ID at the gate.
                    @else
                        Approved. The visit with {{ $status['visit']->pdl?->full_name }} is booked for
                        <strong>{{ $status['visit']->schedule?->schedule_date?->format('D, M j, Y') }} · {{ $bookedTimes }}</strong>.
                        Check the visitor in on that day.
                    @endif
                    @break
                @case('inside')
                    The visitor is checked in. Check them out on the Check-In / Check-Out page when they leave.
                    @break
                @case('returning')
                    Registered walk-in. When they come back to visit, record a new arrival below; no need to register them again.
                    @break
                @default
                    The visitor has checked out. The visit is finished.
            @endswitch
        </template>

        @php
            $id = $visitor->idDocuments->first();
            $checkInToday = $status['key'] === 'booked' && $status['visit']->schedule?->schedule_date?->isToday();
        @endphp
        <dl class="mt-md grid grid-cols-1 gap-md text-body sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="cc-label">Date of birth / age</dt><dd class="mt-xs font-semibold">{{ $visitor->date_of_birth?->format('M j, Y') ?? '—' }} ({{ $visitor->date_of_birth?->age }})</dd></div>
            <div><dt class="cc-label">Sex</dt><dd class="mt-xs font-semibold">{{ $visitor->gender ? ucfirst($visitor->gender) : '—' }}</dd></div>
            <div><dt class="cc-label">Contact number</dt><dd class="mt-xs font-semibold">{{ $visitor->contact_number && $visitor->contact_number !== 'N/A' ? $visitor->contact_number : '—' }}</dd></div>
            {{-- Needed for the mobile app (Forgot Password); a walk-in registered without one can get it added here, once. --}}
            <div>
                <dt class="cc-label">Email</dt>
                @if ($visitor->account?->email)
                    <dd class="mt-xs break-words font-semibold">{{ $visitor->account->email }}</dd>
                @else
                    <dd class="mt-xs"><button type="button" data-email-open class="font-semibold text-primary-teal hover:underline">+ Add email</button></dd>
                @endif
            </div>
            <div class="sm:col-span-2"><dt class="cc-label">Address</dt><dd class="mt-xs font-semibold">{{ $visitor->address ?: '—' }}</dd></div>
            <div><dt class="cc-label">Valid ID</dt><dd class="mt-xs font-semibold">{{ $id ? $id->typeLabel() : '—' }}</dd></div>
            <div><dt class="cc-label">Relationship to PDL</dt><dd class="mt-xs font-semibold">{{ $visitor->relationship_hint ?: '—' }}</dd></div>
        </dl>
    </div>

    {{-- The visit they came for (as Today's Visit on the visitor profile). --}}
    <div class="cc-card">
        <div class="flex flex-wrap items-start justify-between gap-sm">
            <div>
                <p class="text-card-title">Visit Details</p>
                <p class="text-metadata text-text-secondary">What the visitor asked for at the desk.</p>
            </div>
            @if ($checkInToday)
                <a href="{{ route('frontdesk.checkin-checkout', ['checkin' => $visitor->full_name]) }}" class="cc-btn-primary">Check In Now</a>
            @elseif ($status['key'] === 'inside')
                <a href="{{ route('frontdesk.checkin-checkout') }}" class="cc-btn-primary">Go to Check-In / Check-Out</a>
            @endif
        </div>
        <dl class="mt-md grid grid-cols-1 gap-md text-body sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="cc-label">PDL selected</dt><dd class="mt-xs font-semibold">{{ $assessment?->pdl?->listName() ?? '—' }}</dd>
                @if ($assessment?->pdl)<dd class="text-metadata text-text-secondary">{{ $assessment->pdl->pdl_number }}</dd>@endif
            </div>
            <div><dt class="cc-label">PDL as given</dt><dd class="mt-xs font-semibold">{{ $visitor->intended_pdl_name ?: '—' }}</dd>
                @if ($visitor->intended_pdl_number)<dd class="text-metadata text-text-secondary">{{ $visitor->intended_pdl_number }}</dd>@endif
            </div>
            <div><dt class="cc-label">Purpose of visit</dt><dd class="mt-xs font-semibold">{{ $assessment?->visit_purpose ?: '—' }}</dd></div>
            <div><dt class="cc-label">Visit date and session</dt><dd class="mt-xs font-semibold">{{ $assessment?->requestedVisitLabel() ?? 'Not chosen yet' }}</dd>
                @foreach ($assessment?->extraVisitLabels() ?? [] as $label)
                    <dd class="font-semibold">{{ $label }}</dd>
                @endforeach
            </div>
        </dl>
    </div>

    {{-- Every booked day ahead (or today), e.g. this visit plus one for another day. --}}
    @if ($upcomingVisits->isNotEmpty())
        <div class="cc-card">
            <p class="text-card-title">Booked Visits</p>
            <p class="text-metadata text-text-secondary">Approved visits from today on. Check in each one on its own day.</p>
            <ul class="mt-md divide-y divide-border">
                @foreach ($upcomingVisits as $upcoming)
                    <li class="flex flex-wrap items-center justify-between gap-sm py-sm text-body">
                        <span class="font-semibold">
                            {{ $upcoming->schedule->schedule_date->format('D, M j, Y') }} ·
                            {{ $upcoming->sessionSchedules->map(fn ($s) => substr($s->time_slot_start, 0, 5).'–'.substr($s->time_slot_end, 0, 5))->implode(' · ') }}
                        </span>
                        <span class="text-text-secondary">{{ $upcoming->pdl?->listName() }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- What the desk was given and what the system checked (as IDs on File / PDLs on the visitor profile). --}}
    @if ($assessment)
        @php
            $decided = $assessment->isDecided() || $assessment->visit_request_id;
            $pdlCheck = $decided && $assessment->pdl_verification ? $assessment->pdl_verification : $checks['pdl_verification'];
            $checkedPdl = $checks['linkedPdls']->first() ?? $checks['matches']->first();
            $files = $checks['ids']->map(fn ($doc) => ['label' => 'Valid ID · '.$doc->typeLabel(), 'status' => $doc->verification_status, 'url' => route('frontdesk.walk-ins.file', [$visitor, 'id', $doc->visitor_id_doc_id])])
                ->concat($checks['documents']->map(fn ($doc) => ['label' => $doc->label(), 'status' => $doc->status, 'url' => route('frontdesk.walk-ins.file', [$visitor, 'document', $doc->document_id])]));
        @endphp
        <div class="grid grid-cols-1 gap-lg lg:grid-cols-2">
            <div class="cc-card">
                <p class="text-card-title">Files Given at the Desk</p>
                <p class="text-metadata text-text-secondary">Compare these with the original ID and documents.</p>
                <ul class="mt-md divide-y divide-border">
                    @forelse ($files as $file)
                        <li class="flex flex-wrap items-center justify-between gap-sm py-sm">
                            <p class="text-body font-semibold">{{ $file['label'] }}</p>
                            <div class="flex items-center gap-sm">
                                @include('admin.partials.status-chip', ['status' => $file['status'] ?? 'pending'])
                                <a href="{{ $file['url'] }}" target="_blank" rel="noopener" class="text-metadata font-semibold text-primary-teal hover:underline">View file</a>
                            </div>
                        </li>
                    @empty
                        <li class="py-sm text-metadata text-text-secondary">No files.</li>
                    @endforelse
                </ul>
            </div>

            <div class="cc-card">
                <p class="text-card-title">PDL Check</p>
                <p class="text-metadata text-text-secondary">Checked automatically against the PDL records.</p>
                <ul class="mt-md divide-y divide-border">
                    <li class="flex flex-wrap items-center justify-between gap-sm py-sm">
                        <div>
                            <p class="text-body font-semibold">
                                {{ $checkedPdl?->listName() ?? 'No matching PDL' }}
                                @if ($checkedPdl)<span class="font-normal text-text-secondary">({{ $checkedPdl->pdl_number }})</span>@endif
                            </p>
                            <p class="text-metadata text-text-secondary">{{ $visitor->relationship_hint ?: '—' }}</p>
                        </div>
                        <span class="cc-chip gap-xs {{ $pdlCheck === 'verified' ? 'cc-chip-success' : 'cc-chip-danger' }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $pdlCheck === 'verified' ? 'bg-success' : 'bg-danger' }}"></span>
                            {{ \App\Models\WalkInAssessment::OPTIONS['pdl_verification'][$pdlCheck] ?? 'Not checked' }}
                        </span>
                    </li>
                </ul>
            </div>
        </div>
    @endif

    {{-- An arrival recorded without a date and session: the desk chooses them once; they are then locked. --}}
    @if ($canChangeSlot && $slotPdl)
        <div class="cc-card">
            <p class="text-card-title">Choose Visit Date and Session</p>
            <p class="text-metadata text-text-secondary">
                For {{ $slotPdl->listName() }} ({{ $slotPdl->pdl_number }}). Only the sessions Records opened are shown.
                Approving the walk-in books the session you choose. It can't be changed once saved.
            </p>
            <form method="POST" action="{{ route('frontdesk.walk-ins.slot.update', $visitor) }}" class="mt-md">
                @csrf
                @include('frontdesk.walk-ins.partials.slot-picker', [
                    'id' => 'changeSlot',
                    'pdlId' => $slotPdl->pdl_id,
                    'visitorId' => $visitor->visitor_id,
                    'oldDate' => old('requested_date', $assessment->requested_date?->toDateString()),
                    'oldTimes' => old('requested_start_times', $assessment->requested_start_times ?? []),
                ])
                <button type="submit" class="cc-btn-primary mt-md">Save Date and Session</button>
            </form>
        </div>
    @endif

    {{-- A new arrival, no new registration: when the walk-in
         comes back, or to schedule them for another day while a visit is booked. --}}
    @if ($canRecordArrival)
        {{-- Folded away until needed; opens again after a failed submit so the errors show. --}}
        <details class="cc-card group" id="newArrival" @if (old('visit_purpose') !== null) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-md [&::-webkit-details-marker]:hidden">
                <div class="min-w-0">
                    <p class="text-card-title">{{ $upcomingVisits->isNotEmpty() ? 'Schedule Another Day' : 'New Arrival' }}</p>
                    <p class="text-metadata text-text-secondary">{{ $upcomingVisits->isNotEmpty() ? 'Book this visitor for another day.' : 'The visitor is back: record the new arrival.' }}</p>
                </div>
                <span class="cc-btn-secondary shrink-0">
                    <span class="group-open:hidden">Open</span><span class="hidden group-open:inline">Close</span>
                    <svg class="h-4 w-4 transition group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.06l3.71-3.83a.75.75 0 1 1 1.08 1.04l-4.25 4.39a.75.75 0 0 1-1.08 0L5.21 8.27a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
                </span>
            </summary>
            <p class="mt-md border-t border-border pt-md text-metadata text-text-secondary">
                @if ($upcomingVisits->isNotEmpty())
                    Days they already have a visit, and weeks where they've reached the weekly limit, can't be chosen.
                @endif
                Their ID and PDL relationship are already verified. Check their ID, then approve or reject the arrival.
                Approving books the session you choose. The date and session can't be changed once saved.
            </p>
            <form method="POST" action="{{ route('frontdesk.walk-ins.arrivals.store', $visitor) }}" class="mt-md space-y-md">
                @csrf
                <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                    <div>
                        <label for="arrival_purpose" class="cc-label">Purpose of visit *</label>
                        <input id="arrival_purpose" name="visit_purpose" list="arrivalPurposes" required maxlength="150"
                               value="{{ old('visit_purpose') }}" placeholder="Choose or type the purpose" class="cc-input" />
                        <datalist id="arrivalPurposes">
                            @foreach ($purposes as $purpose)
                                <option value="{{ $purpose }}"></option>
                            @endforeach
                        </datalist>
                        @error('visit_purpose') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="arrival_pdl" class="cc-label">PDL to visit *</label>
                        <select id="arrival_pdl" name="pdl_id" required class="cc-input">
                            @foreach ($arrivalPdls as $arrivalPdl)
                                <option value="{{ $arrivalPdl->pdl_id }}" @selected((int) old('pdl_id', $assessment?->pdl_id) === $arrivalPdl->pdl_id)>
                                    {{ $arrivalPdl->listName() }} · {{ $arrivalPdl->pdl_number }}
                                </option>
                            @endforeach
                        </select>
                        @error('pdl_id') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <p class="cc-label">Visit date and session *</p>
                    @include('frontdesk.walk-ins.partials.slot-picker', [
                        'id' => 'arrivalSlots',
                        'pdlId' => null,
                        'pdlSource' => '#arrival_pdl',
                        'visitorId' => $visitor->visitor_id,
                        'oldDate' => old('requested_date'),
                        'oldTimes' => old('requested_start_times', []),
                    ])
                </div>
                {{-- The walk-in assessment for this arrival, decided with it. --}}
                <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                    <div>
                        <label for="arrival_decision" class="cc-label">Walk-in decision *</label>
                        <select id="arrival_decision" name="decision" class="cc-input">
                            <option value="approved" @selected(old('decision', 'approved') === 'approved')>Approve and book the visit</option>
                            <option value="declined" @selected(old('decision') === 'declined')>Reject</option>
                        </select>
                        @error('decision') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="arrival_reason" class="cc-label">Reason for walk-in *</label>
                        <input id="arrival_reason" name="walk_in_reason" maxlength="255" value="{{ old('walk_in_reason') }}" placeholder="e.g. No phone to book in the app" class="cc-input" />
                        @error('walk_in_reason') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
                    </div>
                    <div class="md:col-span-2">
                        <label for="arrival_remarks" class="cc-label">Officer remarks</label>
                        <textarea id="arrival_remarks" name="remarks" rows="2" maxlength="1000" class="cc-input" placeholder="Required when the arrival is rejected">{{ old('remarks') }}</textarea>
                        @error('remarks') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>
                <button type="submit" class="cc-btn-primary">Record New Arrival</button>
            </form>
        </details>
    @endif
    {{-- The walk-in assessment: decided once, by the Front Desk. --}}
    @if ($assessment)
        @php $decisionTone = ['approved' => 'success', 'declined' => 'danger'][$assessment->decision] ?? 'warning'; @endphp
        <div class="cc-card" id="assessment">
            <div class="flex flex-wrap items-start justify-between gap-md">
                <div class="min-w-0 max-w-2xl">
                    <p class="text-card-title">Walk-in Assessment</p>
                    <p class="text-metadata text-text-secondary">
                        @if ($canAssess)
                            Check the original ID and documents, then approve or reject. The decision is final.
                        @else
                            Decided by the Front Desk. The decision is final.
                        @endif
                    </p>
                </div>
                <span class="cc-chip cc-chip-{{ $decisionTone }}">{{ $assessment->label('decision') }}</span>
            </div>

            {{-- The decision: a form until it is made, then what was decided --}}
            <div class="mt-md border-t border-border pt-md">
                @if ($canAssess)
                    <form method="POST" action="{{ route('frontdesk.walk-ins.assessment', $visitor) }}" class="space-y-md"
                          data-confirm="Save the walk-in decision for {{ $visitor->full_name }}? The decision is final."
                          data-confirm-title="Save walk-in decision" data-confirm-ok="Save">
                        @csrf
                        <div class="grid grid-cols-1 gap-md md:grid-cols-2">
                            <div>
                                <label for="assess_decision" class="cc-label">Walk-in decision *</label>
                                <select id="assess_decision" name="decision" required class="cc-input">
                                    <option value="approved" @selected(old('decision', 'approved') === 'approved')>Approve and book the visit</option>
                                    <option value="declined" @selected(old('decision') === 'declined')>Reject</option>
                                </select>
                                @error('decision') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="assess_reason" class="cc-label">Reason for walk-in *</label>
                                <input id="assess_reason" name="walk_in_reason" required maxlength="255" value="{{ old('walk_in_reason', $assessment->walk_in_reason) }}" placeholder="e.g. No phone to book in the app" class="cc-input" />
                                @error('walk_in_reason') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div>
                            <label for="assess_remarks" class="cc-label">Officer remarks</label>
                            <textarea id="assess_remarks" name="remarks" rows="3" maxlength="1000" class="cc-input" placeholder="Required when you reject the walk-in">{{ old('remarks', $assessment->remarks) }}</textarea>
                            @error('remarks') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
                        </div>
                        @if ($assessment->relationship_type === 'legal_guardian')
                            <label class="flex items-start gap-sm rounded-lg border-2 border-border p-md text-body">
                                <input type="checkbox" name="no_immediate_family" value="1" class="mt-1 h-4 w-4 shrink-0" @checked(old('no_immediate_family')) />
                                <span>I confirmed the PDL has <strong>no immediate family</strong> available to visit (required to approve a legal guardian).</span>
                            </label>
                        @endif
                        <div class="flex justify-end">
                            <button type="submit" class="cc-btn-primary">Save Walk-in Decision</button>
                        </div>
                    </form>
                @else
                    <dl class="grid grid-cols-1 gap-x-md gap-y-md text-body sm:grid-cols-2 lg:grid-cols-4">
                        <div><dt class="cc-label">Walk-in decision</dt><dd class="mt-xs font-semibold">{{ $assessment->label('decision') }}</dd></div>
                        <div><dt class="cc-label">Reason for walk-in</dt><dd class="mt-xs font-semibold">{{ $assessment->processed_at ? ($assessment->walk_in_reason ?: '—') : '—' }}</dd></div>
                        <div><dt class="cc-label">Processed by</dt><dd class="mt-xs font-semibold">{{ $assessment->processedBy?->listName() ?? 'Not processed yet' }}</dd></div>
                        <div><dt class="cc-label">Date and time processed</dt><dd class="mt-xs font-semibold">{{ $assessment->processed_at?->format('M j, Y · H:i') ?? '—' }}</dd></div>
                        <div class="sm:col-span-2 lg:col-span-3"><dt class="cc-label">Officer remarks</dt><dd class="mt-xs font-semibold">{{ $assessment->remarks ?: '—' }}</dd></div>
                        <div><dt class="cc-label">Files reviewed</dt><dd class="mt-xs font-semibold">{{ $checks['pendingFiles'] ? $checks['pendingFiles'].' not checked yet' : 'All checked' }}</dd></div>
                    </dl>
                @endif
            </div>
        </div>
    @endif

    @unless ($visitor->account?->email)
        {{-- Add email: opened from "+ Add email" in Visitor details; opens again after a failed save. --}}
        <dialog id="addEmailDialog" class="cc-modal" aria-labelledby="addEmailTitle" @if ($errors->has('email')) data-open-on-load @endif>
            <div class="flex items-center justify-between gap-sm">
                <p id="addEmailTitle" class="text-card-title">Add email</p>
                <button type="button" class="cc-modal-close" data-email-close>Close</button>
            </div>
            <p class="mt-xs text-metadata text-text-secondary">
                For the mobile app. The visitor then uses Forgot Password to sign in. It can't be changed here once saved.
            </p>
            <form method="POST" action="{{ route('frontdesk.walk-ins.email.store', $visitor) }}" class="mt-md">
                @csrf
                <label for="walkInEmail" class="cc-label">Email of {{ $visitor->full_name }}</label>
                <input type="email" id="walkInEmail" name="email" required maxlength="100" value="{{ old('email') }}"
                       placeholder="visitor@example.com" @class(['cc-input', 'border-danger' => $errors->has('email')]) />
                @error('email') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
                <div class="mt-md flex justify-end gap-sm">
                    <button type="button" class="cc-btn-secondary" data-email-close>Cancel</button>
                    <button type="submit" class="cc-btn-primary">Save Email</button>
                </div>
            </form>
        </dialog>
    @endunless
@endsection

@push('scripts')
    <script>
        // Add email popup: the link in Visitor details opens it; Cancel, Close or the dark area closes it.
        (() => {
            const dialog = document.getElementById('addEmailDialog');
            if (!dialog) return;
            document.querySelector('[data-email-open]')?.addEventListener('click', () => dialog.showModal());
            dialog.querySelectorAll('[data-email-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
            dialog.addEventListener('click', e => { if (e.target === dialog) dialog.close(); });
            if (dialog.hasAttribute('data-open-on-load')) dialog.showModal();
        })();

        // Once per status, so the self-refresh below does not repeat it.
        (() => {
            const text = document.getElementById('walkInStatusMessage')?.content.textContent.replace(/\s+/g, ' ').trim();
            const seen = 'walk-in-{{ $visitor->visitor_id }}-{{ $status['key'] }}';
            let shown = false;
            try { shown = sessionStorage.getItem(seen) === '1'; sessionStorage.setItem(seen, '1'); } catch {}
            if (text && !shown) window.ccToast(text, @json(['success' => 'success', 'warning' => 'warning', 'danger' => 'danger'][$status['tone']] ?? 'info'));
        })();
    </script>
@endpush
