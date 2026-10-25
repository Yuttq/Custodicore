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
                    The Records Officer rejected this walk-in. See their remarks in the assessment below and explain them to the visitor.
                    @break
                @case('assess')
                    The ID and PDL relationship are verified. Waiting for the Records Officer's approve or reject decision. This page updates on its own.
                    @break
                @case('linking')
                    The ID is approved. Records is now linking and verifying the PDL relationship.
                    @break
                @case('ready')
                    Approved, but today's visiting hours are over, so the visitor cannot be checked in today.
                    @break
                @case('booked')
                    Approved by Records. Verify the visitor's identity and check them in for
                    <strong>{{ $status['visit']->sessionSchedules->map(fn ($s) => substr($s->time_slot_start, 0, 5).'–'.substr($s->time_slot_end, 0, 5))->implode(' · ') }}</strong>
                    with {{ $status['visit']->pdl?->full_name }}: take their ID at the gate.
                    @break
                @case('inside')
                    The visitor is checked in. Check them out on the Check-In / Check-Out page when they leave.
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
        </dl>


        @if ($status['key'] === 'booked')
            <div class="mt-lg">
                <a href="{{ route('frontdesk.checkin-checkout', ['checkin' => $visitor->full_name]) }}" class="cc-btn-primary">Check In Now</a>
            </div>
        @elseif ($status['key'] === 'inside')
            <div class="mt-lg">
                <a href="{{ route('frontdesk.checkin-checkout') }}" class="cc-btn-primary">Go to Check-In / Check-Out</a>
            </div>
        @endif
    </div>
    {{-- The Records Officer's walk-in assessment (read-only here) --}}
    @if ($assessment)
        <div class="cc-card">
            <div class="flex flex-wrap items-start justify-between gap-sm">
                <div>
                    <p class="text-card-title">Walk-in Assessment</p>
                    <p class="text-metadata text-text-secondary">Done by the Records Officer. You can book the visit once the decision is Approved.</p>
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
            // Follow the Records Officer's progress.
            setInterval(() => { if (!document.hidden) location.reload(); }, 15000);
        </script>
    @endpush
@endif
