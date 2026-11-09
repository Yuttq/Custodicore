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
                    Waiting for the Records Officer to review the ID and documents. This page updates on its own.
                    Ask the visitor to wait nearby.
                    @break
                @case('returned')
                    The Records Officer rejected this registration.
                    <strong>Reason:</strong> {{ $visitor->rejection_reason ?: 'No reason given.' }}
                    Explain it to the visitor; they can come back with what's missing.
                    @break
                @case('declined')
                    The Records Officer rejected this walk-in. The decision is final; see their remarks in the assessment below and explain them to the visitor.
                    @break
                @case('assess')
                    The ID and PDL relationship are verified. Waiting for the Records Officer's approve or reject decision. This page updates on its own.
                    @break
                @case('linking')
                    The ID is approved. Records is now linking and verifying the PDL relationship.
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
                        Approved by Records. Verify the visitor's identity and check them in for
                        <strong>{{ $bookedTimes }}</strong>
                        with {{ $status['visit']->pdl?->full_name }}: take their ID at the gate.
                    @else
                        Approved by Records. The visit with {{ $status['visit']->pdl?->full_name }} is booked for
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
            <div><dt class="cc-label">Valid ID</dt><dd class="mt-xs font-semibold">{{ $id ? $id->typeLabel().' · '.$id->id_number : '—' }}</dd></div>
            <div><dt class="cc-label">Relationship to PDL</dt><dd class="mt-xs font-semibold">{{ $visitor->relationship_hint ?: '—' }}</dd></div>
            <div><dt class="cc-label">PDL name (as given)</dt><dd class="mt-xs font-semibold">{{ $visitor->intended_pdl_name ?: '—' }}</dd></div>
            <div><dt class="cc-label">PDL ID number (as given)</dt><dd class="mt-xs font-semibold">{{ $visitor->intended_pdl_number ?: '—' }}</dd></div>
            <div class="sm:col-span-2"><dt class="cc-label">Purpose of visit</dt><dd class="mt-xs font-semibold">{{ $assessment?->visit_purpose ?: '—' }}</dd></div>
            <div class="sm:col-span-2"><dt class="cc-label">PDL selected</dt><dd class="mt-xs font-semibold">{{ $assessment?->pdl ? $assessment->pdl->full_name.' · '.$assessment->pdl->pdl_number : '—' }}</dd></div>
            <div class="sm:col-span-2"><dt class="cc-label">Visit date and session</dt><dd class="mt-xs font-semibold">{{ $assessment?->requestedVisitLabel() ?? 'Not chosen yet' }}</dd></div>
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

    {{-- The arrival is still waiting for Records: the desk can pick another session. --}}
    @if ($canChangeSlot && $slotPdl)
        <div class="cc-card">
            <p class="text-card-title">{{ $assessment->hasRequestedVisit() ? 'Change Visit Date or Session' : 'Choose Visit Date and Session' }}</p>
            <p class="text-metadata text-text-secondary">
                For {{ $slotPdl->full_name }} ({{ $slotPdl->pdl_number }}). Only the sessions Records opened are shown;
                approving the walk-in books the session chosen here.
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

    {{-- A registered walk-in came back: a new arrival for the Records Officer, no new registration. --}}
    @if ($canRecordArrival)
        <div class="cc-card">
            <p class="text-card-title">New Arrival</p>
            <p class="text-metadata text-text-secondary">
                The visitor is back to visit. Their ID and PDL relationship are already verified; the Records Officer
                only decides on this arrival, and approving it books the session chosen here.
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
                <button type="submit" class="cc-btn-primary">Record New Arrival</button>
            </form>
        </div>
    @endif
    {{-- The Records Officer's walk-in assessment (read-only here) --}}
    @if ($assessment)
        <div class="cc-card">
            <div class="flex flex-wrap items-start justify-between gap-sm">
                <div>
                    <p class="text-card-title">Walk-in Assessment</p>
                    <p class="text-metadata text-text-secondary">Done by the Records Officer, once. Approving books the chosen session.</p>
                </div>
                <span class="font-semibold {{ ['approved' => 'text-success', 'declined' => 'text-danger'][$assessment->decision] ?? 'text-warning' }}">
                    {{ $assessment->label('decision') }}
                </span>
            </div>

            @php
                // Automatic checks: live until the visit is booked, then as saved with the decision.
                $auto = fn ($field) => $assessment->visit_request_id ? $assessment->label($field) : (\App\Models\WalkInAssessment::OPTIONS[$field][$checks[$field]] ?? null);
            @endphp
            <dl class="mt-md grid grid-cols-1 gap-md text-body sm:grid-cols-2 lg:grid-cols-3">
                @foreach (['pdl_verification' => 'PDL verification', 'visitor_eligibility' => 'Visitor eligibility', 'authorized_visitor' => 'Authorized visitor'] as $field => $question)
                    <div><dt class="cc-label">{{ $question }} (automatic)</dt><dd class="mt-xs font-semibold">{{ $auto($field) ?? '—' }}</dd></div>
                @endforeach
                <div><dt class="cc-label">Walk-in decision</dt><dd class="mt-xs font-semibold">{{ $assessment->label('decision') }}</dd></div>
                <div><dt class="cc-label">Reason for walk-in</dt><dd class="mt-xs font-semibold">{{ $assessment->processed_at ? $assessment->label('walk_in_reason') : '—' }}</dd></div>
                <div><dt class="cc-label">Files reviewed</dt><dd class="mt-xs font-semibold">{{ $checks['pendingFiles'] ? $checks['pendingFiles'].' still waiting' : 'All reviewed' }}</dd></div>
                <div class="sm:col-span-2 lg:col-span-3"><dt class="cc-label">Officer remarks</dt><dd class="mt-xs font-semibold">{{ $assessment->remarks ?: '—' }}</dd></div>
                <div><dt class="cc-label">Processed by</dt><dd class="mt-xs font-semibold">{{ $assessment->processedBy?->full_name ?? 'Not yet processed' }}</dd></div>
                <div><dt class="cc-label">Date and time processed</dt><dd class="mt-xs font-semibold">{{ $assessment->processed_at?->format('M j, Y · H:i') ?? '—' }}</dd></div>
            </dl>
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

@if (in_array($status['key'], ['waiting', 'linking', 'assess'], true))
    @push('scripts')
        <script>
            // Follow the Records Officer's progress, but not while the desk is
            // filling in a form (e.g. choosing another session).
            (() => {
                let editing = false;
                document.querySelectorAll('form[method="POST"]').forEach(form => {
                    ['input', 'change', 'click'].forEach(type => form.addEventListener(type, () => { editing = true; }));
                });
                setInterval(() => { if (!document.hidden && !editing) location.reload(); }, 15000);
            })();
        </script>
    @endpush
@endif
