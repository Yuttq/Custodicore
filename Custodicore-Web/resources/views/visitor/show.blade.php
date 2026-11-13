@extends('layouts.app')
@section('title', 'Visitor Profile — CustodiCore')

@section('content')
@php
  $reviewStatus = $visitor->verification_status;
  $reviewBadge = $reviewStatus === 'verified' ? 'active' : ($reviewStatus === 'rejected' ? 'rejected' : 'transferred');
  $reviewLabel = ['verified' => 'APPROVED', 'rejected' => 'RETURNED'][$reviewStatus] ?? strtoupper($reviewStatus);
  // The sign-up hints only help while a PDL still has to be linked or reviewed.
  $showSignUpHints = $visitor->relationships->isEmpty()
      || $visitor->relationships->contains(fn ($r) => $r->verification_status === 'pending');
  // Pending visit requests are listed at the top; Recent Visits shows the rest.
  $pendingVisitIds = $pendingVisitRequests->pluck('visit_request_id')->all();
  $otherVisits = ($visitor->visitRequests ?? collect())->reject(fn ($vr) => in_array($vr->visit_request_id, $pendingVisitIds, true));
@endphp
<div class="topbar">
  <div class="pdl-header">
    @include('partials.visitor-avatar', ['visitor' => $visitor, 'class' => 'pdl-photo-lg visitor-photo-lg'])
    <div>
      <h1 style="margin:0;">{{ $visitor->full_name }}</h1>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:6px;">
        <span class="badge {{ $reviewBadge }}"><span class="dot"></span>{{ $reviewLabel }}</span>
        @if ($visitor->isWalkIn())
          <span class="badge transferred"><span class="dot"></span>Walk-in · registered at the Front Desk</span>
        @endif
      </div>
      <div class="muted-cell" style="margin-top:4px;">
        {{ $visitor->account?->email ?? 'No email on file' }}{{ $visitor->account ? ($visitor->account->email_verified_at ? ' (verified)' : ' (not verified yet)') : '' }}
        @if ($visitor->verified_at) · Reviewed {{ $visitor->verified_at->format('M d, Y H:i') }} @endif
        @if ($visitor->photo_uploaded_at) · photo taken {{ $visitor->photo_uploaded_at->format('M j, Y H:i') }} @else · no photo yet (taken in the app when the account is created) @endif
      </div>
    </div>
  </div>
  <div class="right">
    <a class="btn btn-outline-neutral" href="{{ route('visitor.index') }}">Back to Visitor Management</a>
  </div>
</div>

{{-- ================= VISIT REQUESTS AWAITING REVIEW (first: they need the officer) ================= --}}
@if ($pendingVisitRequests->isNotEmpty())
  @include('visitor.partials.pending-visit-requests', ['showVisitor' => false])
@endif

<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Profile</h2></div>
  <div class="row cols-4" style="margin-bottom:0;">
    <div><div class="stat-label">Date of Birth</div><div>{{ $visitor->date_of_birth->format('M d, Y') }} ({{ $visitor->date_of_birth->age }})</div></div>
    <div><div class="stat-label">Gender</div><div>{{ $visitor->gender ? ucfirst($visitor->gender) : 'Prefer not to say' }}</div></div>
    <div><div class="stat-label">Contact Number</div><div>{{ $visitor->contact_number }}</div></div>
    <div><div class="stat-label">Address</div><div>{{ $visitor->address ?? '—' }}</div></div>
  </div>
  @if ($visitor->emergency_contact_name || $visitor->emergency_contact_number)
  <div class="row" style="margin-top:12px;margin-bottom:0;">
    <div><div class="stat-label">Emergency Contact</div><div>{{ $visitor->emergency_contact_name ?? '—' }} {{ $visitor->emergency_contact_number ? '('.$visitor->emergency_contact_number.')' : '' }}</div></div>
  </div>
  @endif
  @if ($showSignUpHints && ($visitor->relationship_hint || $visitor->intended_pdl_name))
  {{-- Typed by the visitor; a search hint only. Link the PDL with "Add PDL Relationship" below. --}}
  <div class="row cols-2" style="margin-top:12px;margin-bottom:0;">
    @if ($visitor->relationship_hint)
      <div><div class="stat-label">Relationship Hint (from registration)</div><div>{{ $visitor->relationship_hint }}</div></div>
    @endif
    @if ($visitor->intended_pdl_name)
      <div><div class="stat-label">PDL Name Given at Registration (unverified)</div><div>{{ $visitor->intended_pdl_name }}</div></div>
    @endif
  </div>
  @endif
  @if ($visitor->hasGuardianDetails())
  <div class="row cols-3" style="margin-top:16px;margin-bottom:0;">
    <div><div class="stat-label">Guardian Full Name (from registration)</div><div>{{ $visitor->guardian_full_name ?: '—' }}</div></div>
    <div><div class="stat-label">Guardian's Relationship to Visitor</div><div>{{ $visitor->guardian_relationship ?: '—' }}</div></div>
    <div><div class="stat-label">Guardian Contact Number</div><div>{{ $visitor->guardian_contact_number ?: '—' }}</div></div>
  </div>
  @endif
</div>

{{-- ================= WALK-IN ASSESSMENT ================= --}}
@if ($visitor->isWalkIn() && ($assessment = $visitor->walkInAssessments->first()))
  @php
    $options = \App\Models\WalkInAssessment::OPTIONS;
    $checks = app(\App\Services\WalkInService::class)->checks($visitor);
    // The decision is made once: locked when Approved or Rejected (or a visit is booked).
    $locked = $assessment->visit_request_id !== null || $assessment->isDecided();
    // Live checks until the decision; then what was saved with it.
    $auto = fn ($field) => $locked && $assessment->{$field} ? $assessment->{$field} : $checks[$field];
    $value = fn ($field) => old($field, $assessment->{$field});
    $tone = fn ($v) => in_array($v, ['verified', 'yes'], true) ? 'active' : ($v === 'no' || $v === 'not_found' ? 'rejected' : 'transferred');
    $statusBadge = fn ($status) => ['verified' => 'active', 'rejected' => 'rejected'][$status] ?? 'transferred';
    $decisionBadge = ['approved' => 'active', 'declined' => 'rejected'][$assessment->decision] ?? 'transferred';
    $linkedNames = $checks['linkedPdls']->pluck('full_name')->join(', ');
  @endphp
  <div class="panel" id="walk-in" style="margin-bottom:20px;">
    <div class="panel-header">
      <h2>Walk-in Assessment</h2>
      <span class="badge {{ $decisionBadge }}"><span class="dot"></span>{{ $assessment->label('decision') }}</span>
    </div>

    <div class="row cols-3" style="margin-bottom:16px;">
      <div><div class="stat-label">Visitor Type</div><div>Walk-In</div></div>
      <div><div class="stat-label">Registered at the Front Desk by</div><div>{{ $visitor->walkInRegisteredBy?->full_name ?? '—' }} · {{ $visitor->created_at?->format('M j, Y H:i') }}</div></div>
      <div><div class="stat-label">Purpose of Visit</div><div>{{ $assessment->visit_purpose }}</div></div>
      <div><div class="stat-label">PDL Name Given</div><div>{{ $visitor->intended_pdl_name ?: '—' }}</div></div>
      <div><div class="stat-label">PDL ID Number Given</div><div>{{ $visitor->intended_pdl_number ?: '—' }}</div></div>
      <div><div class="stat-label">Relationship Given</div><div>{{ $visitor->relationship_hint ?: '—' }}</div></div>
      <div><div class="stat-label">PDL Selected by the Front Desk</div><div>{{ $assessment->pdl ? $assessment->pdl->full_name.' ('.$assessment->pdl->pdl_number.')' : '—' }}</div></div>
      <div><div class="stat-label">Requested Visit (Booked on Approval)</div><div>{{ $assessment->requestedVisitLabel() ?? 'Not chosen yet' }}</div></div>
    </div>

    {{-- 1. Checks the system fills in --}}
    <h3 style="margin:0 0 8px;font-size:14px;">Automatic Checks</h3>
    <table style="margin-bottom:16px;">
      <thead><tr><th style="width:170px;">Check</th><th style="width:130px;">Status</th><th>Remarks</th></tr></thead>
      <tbody>
        <tr>
          <td><strong>PDL Verification</strong></td>
          <td><span class="badge {{ $tone($auto('pdl_verification')) }}"><span class="dot"></span>{{ $options['pdl_verification'][$auto('pdl_verification')] ?? '—' }}</span></td>
          <td class="muted-cell">
            @if ($checks['linkedPdls']->isNotEmpty())
              Linked to {{ $checks['linkedPdls']->map(fn ($p) => "{$p->full_name} ({$p->pdl_number})")->join(', ') }}.
            @elseif ($checks['matches']->count() === 1)
              @php $match = $checks['matches']->first(); @endphp
              Matches {{ $match->full_name }} ({{ $match->pdl_number }}, {{ ucfirst($match->custody_status) }}).
              Linked automatically as {{ \App\Models\VisitorPdlRelationship::TYPE_LABELS[$assessment->relationship_type] ?? 'the given relationship' }} once every document is approved.
            @elseif ($checks['matches']->isNotEmpty())
              Several PDLs match the name or number given.
            @else
              No PDL matches "{{ $visitor->intended_pdl_name }}"{{ $visitor->intended_pdl_number ? ' / '.$visitor->intended_pdl_number : '' }}. The Front Desk selects the PDL.
            @endif
          </td>
        </tr>
        <tr>
          <td><strong>Visitor Eligibility</strong></td>
          <td><span class="badge {{ $tone($auto('visitor_eligibility')) }}"><span class="dot"></span>{{ $options['visitor_eligibility'][$auto('visitor_eligibility')] ?? '—' }}</span></td>
          <td class="muted-cell">
            Account {{ $visitor->verification_status === 'verified' ? 'approved' : 'not approved yet' }} ·
            ID {{ $visitor->hasVerifiedId() ? 'verified' : 'not verified yet' }} ·
            {{ $checks['flagged'] ? 'Has an active flag' : 'No active flags' }}
          </td>
        </tr>
        <tr>
          <td><strong>Authorized Visitor</strong></td>
          <td><span class="badge {{ $tone($auto('authorized_visitor')) }}"><span class="dot"></span>{{ $options['authorized_visitor'][$auto('authorized_visitor')] ?? '—' }}</span></td>
          <td class="muted-cell">
            @if ($auto('authorized_visitor') === 'yes')
              Relationship to {{ $linkedNames }} approved: every requirement was checked.
            @elseif ($auto('authorized_visitor') === 'no')
              The relationship was rejected.
            @else
              Not approved yet. It is approved automatically once every document and the valid ID are approved.
            @endif
          </td>
        </tr>
      </tbody>
    </table>

    {{-- 2. Every file the visitor gave --}}
    @php
      $claimed = \App\Models\VisitorPdlRelationship::TYPE_LABELS[$assessment->relationship_type] ?? $visitor->relationship_hint;
      $relationshipTag = function ($doc) use ($claimed) {
          return $doc->relationship
              ? $doc->relationship->relationshipLabel().' · '.($doc->relationship->pdl?->full_name ?? 'PDL')
              : $claimed.' · not linked yet';
      };
    @endphp
    <h3 style="margin:0 0 4px;font-size:14px;">Files to Review <span class="count-chip">{{ $checks['pendingFiles'] }} waiting</span></h3>
    <p class="field-hint" style="margin:0 0 8px;">
      Open each file. Check the names and details match the visitor ({{ $visitor->full_name }})
      and the PDL{{ $linkedNames ? " ({$linkedNames})" : ($visitor->intended_pdl_name ? " ({$visitor->intended_pdl_name})" : '') }},
      e.g. that the marriage certificate names both of them, and that nothing looks altered.
    </p>
    <table style="margin-bottom:16px;">
      <thead><tr><th>File</th><th>Relationship</th><th>Status</th><th>Remarks</th><th>Actions</th></tr></thead>
      <tbody>
        <tr>
          <td>Photo of the Visitor</td>
          <td><span class="badge released"><span class="dot"></span>Identity</span></td>
          <td><span class="badge {{ $visitor->photo_path ? 'active' : 'rejected' }}"><span class="dot"></span>{{ $visitor->photo_path ? 'Taken' : 'Missing' }}</span></td>
          <td class="muted-cell">Taken at the Front Desk. Compare it with the ID.</td>
          <td>@if ($visitor->photo_path)<a class="row-action" href="{{ route('visitor.photo', $visitor->visitor_id) }}" target="_blank" rel="noopener">View</a>@endif</td>
        </tr>
        @foreach ($checks['ids'] as $doc)
          <tr>
            <td>{{ $doc->typeLabel() }} · {{ filled($doc->id_number) && strtoupper($doc->id_number) !== 'PENDING' ? $doc->id_number : 'No number given' }}</td>
            <td><span class="badge released"><span class="dot"></span>Identity</span></td>
            <td><span class="badge {{ $statusBadge($doc->verification_status) }}"><span class="dot"></span>{{ ucfirst($doc->verification_status) }}</span></td>
            <td class="muted-cell">
              @if ($doc->verification_status === 'rejected') {{ $doc->rejection_reason ?: 'Rejected.' }}
              @elseif ($doc->verification_status === 'verified') Verified by {{ $doc->verifiedBy?->full_name ?? 'staff' }}
              @else Check it matches the visitor's photo and details.
              @endif
            </td>
            <td>
              <div style="display:flex;gap:8px;align-items:flex-start;">
                @if ($doc->file_path)
                  <a class="row-action" href="{{ route('visitor.ids.file', [$visitor->visitor_id, $doc->visitor_id_doc_id]) }}" target="_blank" rel="noopener">View</a>
                @endif
              </div>
            </td>
          </tr>
        @endforeach
        @foreach ($checks['documents'] as $doc)
          <tr>
            <td>{{ $doc->label() }}</td>
            <td><span class="badge {{ $doc->relationship ? 'detained' : 'released' }}"><span class="dot"></span>{{ $relationshipTag($doc) }}</span></td>
            <td><span class="badge {{ $statusBadge($doc->status) }}"><span class="dot"></span>{{ ucfirst($doc->status) }}</span></td>
            <td class="muted-cell">
              @if ($doc->status === 'rejected') {{ $doc->rejection_reason ?: 'Rejected.' }}
              @elseif ($doc->status === 'verified') Verified by {{ $doc->reviewedBy?->full_name ?? 'staff' }}
              @else Check the names on it match the visitor and the PDL.
              @endif
            </td>
            <td>
              <div style="display:flex;gap:8px;align-items:flex-start;">
                <a class="row-action" href="{{ route('relationship-documents.file', $doc->document_id) }}" target="_blank" rel="noopener">View</a>
              </div>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>

    {{-- 3. The decision, made at the Front Desk --}}
    <p class="muted-cell" style="margin:0 0 12px;">
      @if ($locked)
        This walk-in was {{ $assessment->label('decision') }} at the Front Desk{{ $assessment->visit_request_id ? ' and its visit was booked' : '' }}. The decision is final;
        for another visit, the Front Desk records a new arrival.
      @else
        Waiting for the Front Desk's decision. The Front Desk checks the original ID and documents and approves or rejects walk-ins.
      @endif
    </p>
    <div class="row cols-3" style="margin-bottom:12px;">
      <div><div class="stat-label">Walk-In Decision</div><div>{{ $assessment->label('decision') }}</div></div>
      <div><div class="stat-label">Reason for Walk-In</div><div>{{ $assessment->processed_at ? ($assessment->walk_in_reason ?: '—') : '—' }}</div></div>
      <div><div class="stat-label">Officer Remarks</div><div>{{ $assessment->remarks ?: '—' }}</div></div>
      <div><div class="stat-label">Processed By</div><div>{{ $assessment->processedBy?->full_name ?? 'Not yet processed' }}</div></div>
      <div><div class="stat-label">Date and Time Processed</div><div>{{ $assessment->processed_at?->format('M j, Y H:i') ?? '—' }}</div></div>
    </div>
  </div>
@endif

{{-- ================= ACCOUNT REVIEW ================= --}}
{{-- Only while there is something to do; an approved account's status, email and date are in the header. --}}
{{-- Walk-ins are approved at the Front Desk, with their walk-in assessment. --}}
@if ($reviewStatus !== 'verified' && ! $visitor->isWalkIn())
<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Account Review</h2></div>
  <div class="row cols-3" style="margin-bottom:0;">
    <div>
      <div class="stat-label">Review Status</div>
      <span class="badge {{ $visitor->verification_status === 'verified' ? 'active' : ($visitor->verification_status === 'rejected' ? 'rejected' : 'transferred') }}">
        <span class="dot"></span>{{ ['verified' => 'APPROVED', 'rejected' => 'RETURNED'][$visitor->verification_status] ?? strtoupper($visitor->verification_status) }}
      </span>
    </div>
    <div>
      <div class="stat-label">Email</div>
      <div>{{ $visitor->account?->email ?? '—' }} {{ $visitor->account?->email_verified_at ? '(verified)' : '(not verified yet)' }}</div>
    </div>
    <div>
      <div class="stat-label">Reviewed</div>
      <div>{{ $visitor->verified_at ? $visitor->verified_at->format('M d, Y H:i') : '—' }}</div>
    </div>
  </div>
  @if ($visitor->verification_status === 'rejected' && $visitor->rejection_reason)
    <div class="row" style="margin-top:16px;margin-bottom:0;">
      <div><div class="stat-label">What the visitor was asked to fix</div><div>{{ $visitor->rejection_reason }}</div></div>
    </div>
  @endif

  <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;margin-top:16px;">
    @if ($visitor->verification_status !== 'verified')
      <form method="POST" action="{{ route('visitor.approve', $visitor->visitor_id) }}"
            data-confirm="Approve {{ $visitor->full_name }}? This approves their ID, documents, account and PDL relationship together."
            data-confirm-title="Approve visitor" data-confirm-ok="Approve">
        @csrf
        @php
          $usableId = $visitor->idDocuments->whereIn('verification_status', ['pending', 'verified'])->isNotEmpty();
          $hasLink = $visitor->isWalkIn() || $visitor->relationships->contains(fn ($r) => $r->verification_status !== 'rejected');
        @endphp
        @if (! $usableId)
          <button class="btn btn-blue" type="submit" disabled style="opacity:.45;cursor:not-allowed;" title="The visitor has to upload a government ID first">Approve Visitor</button>
          <div class="field-hint" style="margin-top:6px;">Waiting for the visitor to upload a government ID.</div>
        @elseif (! $hasLink)
          <a class="btn btn-blue" href="{{ route('account-reviews.index') }}">Approve in the Review Queue</a>
          <div class="field-hint" style="margin-top:6px;">Confirm the PDL they will visit on their Review Queue card, or add the PDL relationship below, then approve.</div>
        @else
          <button class="btn btn-blue" type="submit">Approve Visitor</button>
          <div class="field-hint" style="margin-top:6px;">Approves the ID, documents and PDL relationship together with the account.</div>
        @endif
      </form>
    @endif
  </div>
</div>
@endif

{{-- ================= IDENTITY VERIFICATION ================= --}}
<div class="panel" id="identity" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Identity Documents</h2></div>

  @if ($visitor->idDocuments->isEmpty())
    <p class="empty-note">No ID documents uploaded yet.</p>
  @else
    <div class="table-scroll">
    <table>
      <thead><tr><th>Type</th><th>ID Number</th><th>Status</th><th>Approved By</th><th></th></tr></thead>
      <tbody>
        @foreach ($visitor->idDocuments as $doc)
        <tr>
          <td>{{ $doc->typeLabel() }}</td>
          {{-- 'PENDING' is stored when the visitor gave no number; it is not an ID number. --}}
          <td class="muted-cell">{{ filled($doc->id_number) && strtoupper($doc->id_number) !== 'PENDING' ? $doc->id_number : 'No number given' }}</td>
          <td>
            <span class="badge {{ $doc->verification_status === 'verified' ? 'active' : ($doc->verification_status === 'rejected' ? 'rejected' : 'transferred') }}">
              <span class="dot"></span>{{ strtoupper($doc->verification_status === 'verified' ? 'approved' : $doc->verification_status) }}
            </span>
          </td>
          <td class="muted-cell">{{ $doc->verifiedBy?->full_name ?? '—' }}</td>
          <td>
            <div style="display:flex;gap:8px;">
              @if ($doc->file_path)
                <a class="row-action" href="{{ route('visitor.ids.file', [$visitor->visitor_id, $doc->visitor_id_doc_id]) }}" target="_blank" rel="noopener">View ID</a>
              @endif
              @if ($doc->verification_status !== 'verified')
                <form method="POST" action="{{ route('visitor.ids.verify', [$visitor->visitor_id, $doc->visitor_id_doc_id]) }}"
                      data-confirm="Approve this {{ $doc->typeLabel() }}? Only do this after checking it matches the visitor."
                      data-confirm-title="Approve ID" data-confirm-ok="Approve">
                  @csrf
                  <button type="submit" class="row-action" style="color:var(--green);">Approve</button>
                </form>
              @endif
              {{-- Only an ID waiting for review: an approved ID stays approved. --}}
              @if ($doc->verification_status === 'pending')
                <details class="acct-reject">
                  <summary class="row-action" style="cursor:pointer;color:var(--red);">Reject</summary>
                  <form method="POST" action="{{ route('visitor.ids.reject', [$visitor->visitor_id, $doc->visitor_id_doc_id]) }}" class="acct-reject-form"
                        data-confirm="Reject this {{ $doc->typeLabel() }}? The visitor will need to upload a new one."
                        data-confirm-title="Reject ID" data-confirm-ok="Reject" data-confirm-danger>
                    @csrf
                    <textarea name="rejection_reason" required minlength="5" maxlength="500" aria-label="Why the ID was rejected (shown to the visitor)"
                              data-label="Why the ID was rejected (shown to the visitor)"
                              placeholder="e.g. The ID photo is unreadable. Please upload a clearer copy."></textarea>
                    <button type="submit" class="row-action" style="color:var(--red);">Confirm Reject</button>
                  </form>
                </details>
              @endif
            </div>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif
</div>

{{-- ================= RELATIONSHIP VERIFICATION ================= --}}
<div class="panel" id="relationships" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Relationships to PDLs</h2></div>

  @if ($visitor->relationships->isEmpty())
    <p class="empty-note">No PDL relationships registered for this visitor yet.</p>
  @else
    <div class="table-scroll">
    <table>
      <thead><tr><th>PDL</th><th>Relationship</th><th>Priority</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach ($visitor->relationships as $rel)
        @php
          $relRequirements = \App\Services\RelationshipRequirements::for($rel);
          // Ready once only documents waiting for review are left: Approve approves them too.
          $relReady = \App\Services\RelationshipApproval::ready($relRequirements);
          $relBlockers = \App\Services\RelationshipApproval::blockers($relRequirements);
          // An approved relationship is finished: its requirements (and Reject) fold into one line.
          $relApproved = $rel->verification_status === 'verified';
          $relMet = collect($relRequirements)->where('status', 'met')->count();
        @endphp
        <tr>
          <td>
            @if ($rel->pdl)
              <a href="{{ route('pdl.show', $rel->pdl->pdl_id) }}" style="color:var(--text);text-decoration:none;font-weight:600;">
                {{ $rel->pdl->full_name }}
              </a>
            @else
              <span class="muted-cell">PDL not found</span>
            @endif
          </td>
          <td class="muted-cell">{{ $rel->relationshipLabel() }}</td>
          <td>
            @if ($rel->isHighPriority())
              <span class="pill" style="background:var(--blue-bg);color:var(--blue);">HIGH PRIORITY</span>
            @else
              <span class="muted-cell">Needs approval</span>
            @endif
          </td>
          <td>
            <span class="badge {{ $rel->verification_status === 'verified' ? 'active' : ($rel->verification_status === 'rejected' ? 'rejected' : 'transferred') }}">
              <span class="dot"></span>{{ strtoupper($rel->verification_status === 'verified' ? 'approved' : $rel->verification_status) }}
            </span>
          </td>
          <td>
            <div style="display:flex;gap:8px;">
              {{-- Only a pending relationship: a rejected one is reopened by a new Add Another PDL request. --}}
              @if ($rel->verification_status === 'pending')
                @include('visitor.partials.relationship-approve', ['rel' => $rel])
              @endif
              {{-- Pending: Approve or Return (what to fix). Approved: Revoke Approval sits in the folded details below. --}}
              @if ($rel->verification_status === 'pending')
                @include('visitor.partials.relationship-return', ['rel' => $rel])
              @elseif ($relApproved)
                <span class="muted-cell">{{ $relMet }} of {{ count($relRequirements) }} met</span>
              @endif
            </div>
          </td>
        </tr>
        <tr class="req-row">
          <td colspan="5">
            @if ($relApproved)
              <details>
                <summary class="row-action" style="cursor:pointer;">Show requirements and files</summary>
                <div style="margin-top:10px;">
                  @include('partials.relationship-requirements', ['relationship' => $rel, 'items' => $relRequirements])
                  <div style="margin-top:10px;">
                    @include('visitor.partials.relationship-reject', ['rel' => $rel])
                  </div>
                </div>
              </details>
            @else
              @include('partials.relationship-requirements', ['relationship' => $rel, 'items' => $relRequirements])
            @endif
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif

  @include('partials.registration-documents', ['visitor' => $visitor])

  <details style="margin-top:14px;" {{ $errors->hasAny(['pdl_id', 'relationship_type', 'has_children_together']) && ! old('schedule_id') ? 'open' : '' }}>
    <summary class="row-action" style="cursor:pointer;">Add PDL Relationship</summary>
    @if ($relatablePdls->isEmpty())
      <p class="empty-note" style="margin-top:12px;">No other PDLs are available to relate to this visitor.</p>
    @else
      <form method="POST" action="{{ route('visitor.relationships.store', $visitor->visitor_id) }}" style="margin-top:12px;"
            data-confirm="Add this PDL relationship for {{ $visitor->full_name }}? It starts as pending until its requirements are met."
            data-confirm-title="Add relationship" data-confirm-ok="Add">
        @csrf
        <div class="row cols-3">
          @php
            // Re-select the PDL after a failed submit (not the assign-visit form's pdl_id).
            $preselected = ! old('schedule_id') && old('pdl_id') ? $relatablePdls->firstWhere('pdl_id', (int) old('pdl_id')) : null;
          @endphp
          <div class="field-m pdl-search" data-pdl-search data-url="{{ route('visitor.relatable-pdls', $visitor->visitor_id) }}">
            <label for="pdlSearchInput">PDL</label>
            <input type="hidden" name="pdl_id" value="{{ $preselected?->pdl_id }}" data-pdl-id>
            <div class="pdl-search-box">
              <input type="text" id="pdlSearchInput" name="pdl_search" autocomplete="off" data-label="PDL"
                     role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="pdlSearchResults"
                     placeholder="Search name, alias, PDL number or ID"
                     value="{{ $preselected ? $preselected->full_name . ' (' . $preselected->pdl_number . ')' : '' }}">
              <ul class="pdl-search-results" id="pdlSearchResults" role="listbox" hidden></ul>
            </div>
            <p class="field-hint" data-pdl-hint>Type at least 1 character, then pick the PDL from the list.</p>
          </div>
          <div class="field-m">
            <label>Relationship Type</label>
            <select name="relationship_type" required data-rel-type>
              <option value="">— Select type —</option>
              @foreach (\App\Models\VisitorPdlRelationship::RELATIONSHIP_TYPES as $type)
                <option value="{{ $type }}" {{ old('relationship_type') === $type ? 'selected' : '' }}>{{ \App\Models\VisitorPdlRelationship::TYPE_LABELS[$type] }}</option>
              @endforeach
            </select>
          </div>
          <div class="field-m">
            <label>Children together (live-in partners)</label>
            <select name="has_children_together" data-children-select>
              <option value="">Not recorded yet</option>
              <option value="1" {{ old('has_children_together') === '1' ? 'selected' : '' }}>Has children together</option>
              <option value="0" {{ old('has_children_together') === '0' ? 'selected' : '' }}>No children together</option>
            </select>
          </div>
        </div>
        <div class="muted-cell" style="margin-bottom:12px;font-size:12px;">
          Only direct relationships can visit. Friends and non-relatives are not allowed; extended relatives need official authorization.
          Spouse, parent and legal guardian get high priority automatically. The relationship starts as pending, and the visitor
          then uploads the required documents from the app.
        </div>
        <button class="btn btn-blue" type="submit">Add Relationship</button>
      </form>
    @endif
  </details>
</div>

@include('partials.pdl-search-script')

{{-- ================= RECENT VISITS (pending requests are listed at the top) ================= --}}
@if ($otherVisits->isNotEmpty())
<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Recent Visits</h2></div>
  <div class="table-scroll">
  <table>
    <thead><tr><th>PDL</th><th>Schedule</th><th>Status</th><th>Assigned</th></tr></thead>
    <tbody>
      @foreach ($otherVisits as $vr)
      <tr>
        <td>{{ $vr->pdl?->full_name ?? '—' }}</td>
        <td class="muted-cell">
          @if ($vr->schedule)
            {{ $vr->schedule->schedule_date->format('M d, Y') }}
            · {{ ($vr->sessionSchedules->isNotEmpty() ? $vr->sessionSchedules : collect([$vr->schedule]))->map(fn ($s) => substr($s->time_slot_start, 0, 5).'–'.substr($s->time_slot_end, 0, 5))->implode(' and ') }}
            @if ($vr->sessionSchedules->count() > 1)
              (whole day)
            @endif
          @else
            —
          @endif
        </td>
        <td>
          {{-- `assigned` is a visitor-submitted request awaiting staff review. --}}
          <span class="badge transferred"><span class="dot"></span>{{ $vr->status === 'assigned' ? 'AWAITING REVIEW' : strtoupper(str_replace('_', ' ', $vr->status)) }}</span>
        </td>
        <td class="muted-cell">{{ optional($vr->assigned_at)->format('M d, Y') ?? '—' }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
  </div>
</div>
@endif

{{-- ================= VISITOR HISTORY / FLAGS ================= --}}
<div class="panel" id="flags">
  <div class="panel-header">
    <h2>Flags</h2>
    @if ($visitor->flags->isEmpty())
      <span class="muted-cell">None — clean history.</span>
    @endif
  </div>

  @if ($visitor->flags->isNotEmpty())
    <div class="table-scroll">
    <table>
      <thead><tr><th>Type</th><th>Description</th><th>Status</th><th>How Long</th><th>Flagged By</th><th>Date</th><th></th></tr></thead>
      <tbody>
        @foreach ($visitor->flags as $flag)
        <tr>
          <td>{{ $flag->typeLabel() }}</td>
          <td class="muted-cell">{{ $flag->description }}</td>
          <td>
            <span class="badge {{ $flag->isActive() ? 'transferred' : 'released' }}">
              <span class="dot"></span>{{ $flag->isActive() ? 'ACTIVE' : ($flag->hasExpired() ? 'EXPIRED' : 'RESOLVED') }}
            </span>
          </td>
          <td class="muted-cell">{{ $flag->durationLabel() }}</td>
          <td class="muted-cell">{{ $flag->flaggedBy?->full_name ?? '—' }}</td>
          <td class="muted-cell">{{ $flag->created_at->format('M d, Y') }}</td>
          <td>
            @if ($flag->status === 'active')
              <form method="POST" action="{{ route('visitor.flags.resolve', [$visitor->visitor_id, $flag->flag_id]) }}"
                    data-confirm="Mark this flag as resolved?" data-confirm-title="Resolve flag" data-confirm-ok="Resolve">
                @csrf
                <button type="submit" class="row-action">Resolve</button>
              </form>
            @endif
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  @endif

  <details style="{{ $visitor->flags->isNotEmpty() ? 'margin-top:14px;' : '' }}">
    <summary class="row-action" style="cursor:pointer;">Flag This Visitor</summary>
    <form method="POST" action="{{ route('visitor.flags.store', $visitor->visitor_id) }}" style="margin-top:12px;"
          data-confirm="Flag {{ $visitor->full_name }}? Active flags send their visit requests to eligibility review."
          data-confirm-title="Flag visitor" data-confirm-ok="Add flag" data-confirm-danger>
      @csrf
      <div class="row cols-2">
        <div class="field-m">
          <label>Flag Type</label>
          <select name="flag_type" required>
            <option value="denied_visit">Denied Visit</option>
            <option value="disciplinary_issue">Disciplinary Issue</option>
            <option value="officer_conflict">Officer Conflict</option>
            <option value="rule_violation">Rule Violation</option>
            <option value="prohibited_item_attempt">Prohibited Item Attempt</option>
            <option value="other">Other</option>
          </select>
        </div>
      </div>
      <div class="row">
        <div class="field-m"><label>Description</label><textarea name="description" required minlength="10" maxlength="1000"></textarea></div>
      </div>
      <div class="row cols-2">
        <div class="field-m">
          <label for="flag_duration">How long?</label>
          <select id="flag_duration" name="duration" required>
            @foreach (\App\Models\VisitorFlag::DURATIONS as $value => $label)
              <option value="{{ $value }}" @selected(old('duration', '30') === (string) $value)>{{ $label }}</option>
            @endforeach
          </select>
          <p class="field-hint">While the flag applies, this visitor's visit requests go to the Review Queue. It ends automatically after this time, or when resolved.</p>
        </div>
      </div>
      <button class="btn btn-blue" type="submit">Add Flag</button>
    </form>
  </details>
</div>

{{-- ================= ACTIVITY HISTORY ================= --}}
<div class="panel" style="margin-top:20px;">
  <div class="panel-header">
    <h2>Activity History</h2>
    <a class="row-action" href="{{ route('activity-log.index', ['q' => $visitor->full_name]) }}">Open in Activity Log</a>
  </div>
  {{-- Latest 5 events; the rest (and "Registered") behind Show all. --}}
  @if ($activity->count() <= 5)
    @include('partials.activity-timeline', ['entries' => $activity, 'registeredAt' => $visitor->created_at])
  @else
    @include('partials.activity-timeline', ['entries' => $activity->take(5)])
    <details style="margin-top:8px;">
      <summary class="row-action" style="cursor:pointer;">Show {{ $activity->count() - 5 + 1 }} older</summary>
      <div class="timeline-scroll" style="margin-top:8px;">
        @include('partials.activity-timeline', ['entries' => $activity->slice(5)->values(), 'registeredAt' => $visitor->created_at])
      </div>
    </details>
  @endif
</div>
@endsection
