{{--
  Relationship documents the visitor sent with the registration that are not
  on a relationship checklist yet (no PDL linked, or the linked relationship
  does not ask for them). Read-only: staff verify or reject each one on the
  checklist after linking the PDL with "Add PDL Relationship".
  Expects: $visitor — VisitorProfile with registrationDocuments loaded.
--}}
@php $registrationDocs = $visitor->registrationDocuments->unique('requirement_key'); @endphp
@if ($registrationDocs->isNotEmpty())
  <div class="req-panel" style="margin-top:12px;">
    <div class="req-head">
      <strong>Submitted with registration · not linked to a PDL yet</strong>
    </div>
    <ul class="req-list">
      @foreach ($registrationDocs as $doc)
        <li class="req-item">
          <div class="req-main">
            <div class="req-title">{{ $doc->label() }}</div>
            <div class="req-meta">
              <a href="{{ route('relationship-documents.file', $doc->document_id) }}" target="_blank" rel="noopener">View uploaded file</a>
              · uploaded {{ $doc->uploaded_at?->format('M j, Y H:i') }}
            </div>
          </div>
        </li>
      @endforeach
    </ul>
    <p class="muted-cell" style="margin:6px 0 0 0;font-size:12px;">
      Link the PDL with the matching relationship and these files move onto its requirements checklist for review.
    </p>
  </div>
@endif
