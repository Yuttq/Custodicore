@extends('layouts.frontdesk')

@section('title', 'Walk-in Visitor')

@section('header_actions')
    <a href="{{ route('frontdesk.walk-ins.index') }}" class="cc-btn-secondary">Back to Walk-in Visitors</a>
@endsection

@section('content')
    <div class="cc-card">
        <div class="flex flex-wrap items-start justify-between gap-sm">
            <div class="flex items-center gap-sm">
                @include('admin.partials.avatar', ['name' => $visitor->full_name, 'size' => 'h-14 w-14', 'photo' => $visitor->photoUrl()])
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
                        Wants to visit <strong>{{ $visitor->intended_pdl_name ?: '—' }}</strong> · {{ $visitor->relationship_hint }}
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

        {{-- What was recorded at registration --}}
        @php $id = $visitor->idDocuments->first(); @endphp
        <dl class="mt-md grid grid-cols-1 gap-md text-body sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="cc-label">Visitor type</dt><dd class="mt-xs"><span class="cc-chip cc-chip-info">Walk-In</span></dd></div>
            <div><dt class="cc-label">Date of birth / age</dt><dd class="mt-xs font-semibold">{{ $visitor->date_of_birth?->format('M j, Y') }} ({{ $visitor->date_of_birth?->age }})</dd></div>
            <div><dt class="cc-label">Sex</dt><dd class="mt-xs font-semibold">{{ ucfirst($visitor->gender ?? '—') }}</dd></div>
            <div><dt class="cc-label">Contact number</dt><dd class="mt-xs font-semibold">{{ $visitor->contact_number && $visitor->contact_number !== 'N/A' ? $visitor->contact_number : '—' }}</dd></div>
            <div class="sm:col-span-2"><dt class="cc-label">Address</dt><dd class="mt-xs font-semibold">{{ $visitor->address ?: '—' }}</dd></div>
            <div><dt class="cc-label">Valid ID</dt><dd class="mt-xs font-semibold">{{ $id ? $id->typeLabel() : '—' }}</dd></div>
            <div><dt class="cc-label">Relationship to PDL</dt><dd class="mt-xs font-semibold">{{ $visitor->relationship_hint ?: '—' }}</dd></div>
            <div><dt class="cc-label">PDL name (as given)</dt><dd class="mt-xs font-semibold">{{ $visitor->intended_pdl_name ?: '—' }}</dd></div>
            <div><dt class="cc-label">PDL number (as given)</dt><dd class="mt-xs font-semibold">{{ $visitor->intended_pdl_number ?: '—' }}</dd></div>
            <div class="sm:col-span-2"><dt class="cc-label">Purpose of visit</dt><dd class="mt-xs font-semibold">{{ $assessment?->visit_purpose ?: '—' }}</dd></div>
            <div class="sm:col-span-2"><dt class="cc-label">PDL selected</dt><dd class="mt-xs font-semibold">{{ $assessment?->pdl ? $assessment->pdl->full_name.' · '.$assessment->pdl->pdl_number : '—' }}</dd></div>
            <div class="sm:col-span-2"><dt class="cc-label">Visit date and session</dt><dd class="mt-xs font-semibold">{{ $assessment?->requestedVisitLabel() ?? 'Not chosen yet' }}</dd>
                @foreach ($assessment?->extraVisitLabels() ?? [] as $label)
                    <dd class="font-semibold">{{ $label }}</dd>
                @endforeach
            </div>
        </dl>


        @if ($status['key'] === 'booked' && $status['visit']->schedule?->schedule_date?->isToday())
            <div class="mt-lg">
                <a href="{{ route('frontdesk.checkin-checkout', ['checkin' => $visitor->full_name]) }}" class="cc-btn-primary">Check In Now</a>
            </div>
        @elseif ($status['key'] === 'inside')
            <div class="mt-lg">
                <a href="{{ route('frontdesk.checkin-checkout') }}" class="cc-btn-primary">Go to Check-In / Check-Out</a>
            </div>
        @endif
    </div>

    {{-- Every booked day ahead (or today), e.g. this visit plus one for another day. --}}
    @if ($upcomingVisits->isNotEmpty())
        <div class="cc-card">
            <p class="text-card-title">Booked Visits</p>
            <p class="text-metadata text-text-secondary">Approved visits for this visitor, today and later. Each one is checked in on its own day.</p>
            <ul class="mt-md divide-y divide-border">
                @foreach ($upcomingVisits as $upcoming)
                    <li class="flex flex-wrap items-center justify-between gap-sm py-sm text-body">
                        <span class="font-semibold">
                            {{ $upcoming->schedule->schedule_date->format('D, M j, Y') }} ·
                            {{ $upcoming->sessionSchedules->map(fn ($s) => substr($s->time_slot_start, 0, 5).'–'.substr($s->time_slot_end, 0, 5))->implode(' · ') }}
                        </span>
                        <span class="text-text-secondary">{{ $upcoming->pdl?->full_name }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- An arrival recorded without a date and session: the desk chooses them once; they are then locked. --}}
    @if ($canChangeSlot && $slotPdl)
        <div class="cc-card">
            <p class="text-card-title">Choose Visit Date and Session</p>
            <p class="text-metadata text-text-secondary">
                For {{ $slotPdl->full_name }} ({{ $slotPdl->pdl_number }}). Only the sessions Records opened are shown;
                approving the walk-in books the session chosen here. Once saved, it cannot be changed.
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
        <div class="cc-card">
            <p class="text-card-title">{{ $upcomingVisits->isNotEmpty() ? 'Schedule Another Day' : 'New Arrival' }}</p>
            <p class="text-metadata text-text-secondary">
                @if ($upcomingVisits->isNotEmpty())
                    Book this visitor for another day. Days they already have a visit, and weeks where they've reached the weekly limit, can't be chosen.
                @else
                    The visitor is back to visit.
                @endif
                Their ID and PDL relationship are already verified. Check their ID, then approve or reject the arrival here; approving books the session chosen here.
                The date and session can't be changed after you record it.
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
                                    {{ $arrivalPdl->full_name }} · {{ $arrivalPdl->pdl_number }}
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
        </div>
    @endif
    {{-- The walk-in assessment: decided once, by the Front Desk. --}}
    @if ($assessment)
        <div class="cc-card" id="assessment">
            <div class="flex flex-wrap items-start justify-between gap-sm">
                <div>
                    <p class="text-card-title">Walk-in Assessment</p>
                    <p class="text-metadata text-text-secondary">Decided at the Front Desk when the walk-in was saved. If an approval was blocked, fix the reason, then approve or reject it here. The decision is final.</p>
                </div>
                <span class="font-semibold {{ ['approved' => 'text-success', 'declined' => 'text-danger'][$assessment->decision] ?? 'text-warning' }}">
                    {{ $assessment->label('decision') }}
                </span>
            </div>

            {{-- Filled in by the system --}}
            @php
                $decided = $assessment->isDecided() || $assessment->visit_request_id;
                $options = \App\Models\WalkInAssessment::OPTIONS;
                $pdlCheck = $decided && $assessment->pdl_verification ? $assessment->pdl_verification : $checks['pdl_verification'];
                $checkedPdl = $checks['linkedPdls']->first() ?? $checks['matches']->first();
            @endphp
            <dl class="mt-md text-body">
                <div>
                    <dt class="cc-label">PDL verification (automatic)</dt>
                    <dd class="mt-xs font-semibold {{ $pdlCheck === 'verified' ? 'text-success' : 'text-danger' }}">
                        {{ $options['pdl_verification'][$pdlCheck] ?? '—' }}{{ $checkedPdl ? ' · '.$checkedPdl->full_name.' ('.$checkedPdl->pdl_number.')' : '' }}
                    </dd>
                </div>
            </dl>
            @if ($checks['flagged'])
                <p class="mt-sm text-body font-semibold text-danger">Active flag: this visitor can't be approved until the flag is resolved.</p>
            @endif

            {{-- The files given at the desk --}}
            <div class="mt-md">
                <p class="cc-label">Files</p>
                <ul class="mt-xs divide-y divide-border rounded-lg border border-border">
                    @forelse ($checks['ids']->map(fn ($id) => ['label' => 'Valid ID · '.$id->typeLabel(), 'status' => $id->verification_status, 'url' => route('frontdesk.walk-ins.file', [$visitor, 'id', $id->visitor_id_doc_id])])
                        ->concat($checks['documents']->map(fn ($doc) => ['label' => $doc->label(), 'status' => $doc->status, 'url' => route('frontdesk.walk-ins.file', [$visitor, 'document', $doc->document_id])])) as $file)
                        <li class="flex flex-wrap items-center justify-between gap-sm px-md py-sm text-body">
                            <a href="{{ $file['url'] }}" target="_blank" rel="noopener" class="font-semibold text-primary-teal underline">{{ $file['label'] }}</a>
                            <span class="{{ ['verified' => 'text-success', 'rejected' => 'text-danger'][$file['status']] ?? 'text-warning' }}">{{ ['verified' => 'Verified', 'rejected' => 'Rejected'][$file['status']] ?? 'Not checked yet' }}</span>
                        </li>
                    @empty
                        <li class="px-md py-sm text-body text-text-secondary">No files.</li>
                    @endforelse
                </ul>
            </div>

            @if ($canAssess)
                <form method="POST" action="{{ route('frontdesk.walk-ins.assessment', $visitor) }}" class="mt-lg space-y-md"
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
                        <textarea id="assess_remarks" name="remarks" rows="3" maxlength="1000" class="cc-input" placeholder="Required when the walk-in is rejected">{{ old('remarks', $assessment->remarks) }}</textarea>
                        @error('remarks') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
                    </div>
                    @if ($assessment->relationship_type === 'legal_guardian')
                        <label class="flex items-start gap-sm rounded-lg border-2 border-border p-md text-body">
                            <input type="checkbox" name="no_immediate_family" value="1" class="mt-1 h-4 w-4 shrink-0" @checked(old('no_immediate_family')) />
                            <span>I confirmed the PDL has <strong>no immediate family</strong> available to visit (required to approve a legal guardian).</span>
                        </label>
                    @endif
                    <button type="submit" class="cc-btn-primary">Save Walk-in Decision</button>
                </form>
            @else
                <dl class="mt-md grid grid-cols-1 gap-md text-body sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="cc-label">Walk-in decision</dt><dd class="mt-xs font-semibold">{{ $assessment->label('decision') }}</dd></div>
                    <div><dt class="cc-label">Reason for walk-in</dt><dd class="mt-xs font-semibold">{{ $assessment->processed_at ? ($assessment->walk_in_reason ?: '—') : '—' }}</dd></div>
                    <div><dt class="cc-label">Files reviewed</dt><dd class="mt-xs font-semibold">{{ $checks['pendingFiles'] ? $checks['pendingFiles'].' not checked yet' : 'All checked' }}</dd></div>
                    <div class="sm:col-span-2 lg:col-span-3"><dt class="cc-label">Officer remarks</dt><dd class="mt-xs font-semibold">{{ $assessment->remarks ?: '—' }}</dd></div>
                    <div><dt class="cc-label">Processed by</dt><dd class="mt-xs font-semibold">{{ $assessment->processedBy?->full_name ?? 'Not yet processed' }}</dd></div>
                    <div><dt class="cc-label">Date and time processed</dt><dd class="mt-xs font-semibold">{{ $assessment->processed_at?->format('M j, Y · H:i') ?? '—' }}</dd></div>
                </dl>
            @endif
        </div>
    @endif
@endsection

@push('scripts')
    <script>
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
