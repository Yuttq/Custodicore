{{--
  Relationship documents the visitor sent with the registration that are not
  on a relationship checklist yet (no PDL linked, or the linked relationship
  does not ask for them). Newest file per requirement.
  While an app-registered account is not approved, staff can reject a file here; the
  visitor is notified and replaces it from the app. Files are approved only
  together with the account (one-step approval), or on the relationship
  checklist after the PDL is linked.
  Expects: $visitor — VisitorProfile with registrationDocuments loaded.
--}}
@php
  $registrationDocs = $visitor->registrationDocuments->unique('requirement_key');
  // Walk-ins keep their own document review (WalkInAssessmentController).
  $canRejectRegistrationDocs = $visitor->verification_status !== 'verified' && ! $visitor->isWalkIn();
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
          @if ($canRejectRegistrationDocs && $doc->status === 'pending')
            <div class="acct-id-actions">
              <details class="acct-reject">
                <summary class="btn btn-outline-neutral acct-small elig-reject">Reject</summary>
                <form method="POST" action="{{ route('relationship-documents.reject', $doc->document_id) }}" class="acct-reject-form"
                      data-confirm="Reject {{ $visitor->full_name }}'s {{ $doc->label() }}? They will be notified and asked to upload a new one."
                      data-confirm-title="Reject document" data-confirm-ok="Reject" data-confirm-danger>
                  @csrf
                  <textarea name="rejection_reason" required minlength="5" maxlength="500" data-label="Why the document was rejected (shown to the visitor)"
                            placeholder="e.g. The certificate is cut off. Please upload a photo of the whole page."></textarea>
                  <button type="submit" class="btn btn-outline-neutral acct-small elig-reject">Confirm reject</button>
                </form>
              </details>
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
