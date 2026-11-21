{{--
  Relationship documents the visitor sent with the registration that are not
  on a relationship checklist yet (no PDL linked, or the linked relationship
  does not ask for them). Newest file per requirement.
  Reject only: staff reject a file waiting for review, or ask for a new copy
  of an approved one, with a reason; the visitor is notified and replaces
  only that file from the app. Files are approved together with the account
  (final Approve), or on the relationship checklist after the PDL is linked.
  Expects: $visitor — VisitorProfile with registrationDocuments loaded.
  Optional: $allowReject (default true) — false hides the Reject controls.
--}}
@php
  $registrationDocs = $visitor->registrationDocuments->unique('requirement_key');
  // Walk-ins keep their own document review (at the Front Desk, WalkInService::approveAtDesk).
  $canRejectRegistrationDocs = ($allowReject ?? true) && ! $visitor->isWalkIn();
@endphp
@if ($registrationDocs->isNotEmpty())
  <div class="req-panel" style="margin-top:12px;">
    <div class="req-head">
      <strong>Submitted with registration · not linked to a PDL yet</strong>
    </div>
    <ul class="req-list">
      @foreach ($registrationDocs as $doc)
        <li class="req-item">
          <div class="req-main">
            <div class="req-title">
              {{ $doc->label() }}
              @if ($doc->status === 'rejected')
                <span class="badge rejected"><span class="dot"></span>Rejected · waiting for a new file</span>
              @elseif ($doc->status === 'pending' && $visitor->registrationDocuments->where('requirement_key', $doc->requirement_key)->where('status', 'rejected')->isNotEmpty())
                <span class="badge transferred"><span class="dot"></span>Replacement · not checked yet</span>
              @endif
            </div>
            <div class="req-meta">
              <a href="{{ route('relationship-documents.file', $doc->document_id) }}" target="_blank" rel="noopener">View uploaded file</a>
              · uploaded {{ $doc->uploaded_at?->format('M j, Y H:i') }}
            </div>
            @if ($doc->status === 'rejected' && $doc->rejection_reason)
              <div class="req-meta">Reason given: {{ $doc->rejection_reason }}</div>
            @endif
          </div>
          @if ($canRejectRegistrationDocs && in_array($doc->status, ['pending', 'verified'], true))
            <div class="acct-id-actions">
              @include('partials.item-reject', [
                'action' => route('relationship-documents.reject', $doc->document_id),
                'label' => $doc->label(),
                'approved' => $doc->status === 'verified',
              ])
            </div>
          @endif
        </li>
      @endforeach
    </ul>
    <p class="muted-cell" style="margin:6px 0 0 0;font-size:12px;">
      Link the PDL with the matching relationship and these files move onto its requirements checklist for review.
    </p>
  </div>
@endif
