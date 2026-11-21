{{--
  Approved visitor: what staff asked to replace, the replacements the visitor
  sent (each with View and its own Reject), and one Accept replacements
  button. Accept only verifies replacements still waiting; anything rejected
  here stays rejected. Shown on the Review Queue card and the visitor page.
  Expects: $visitor (approved VisitorProfile). Optional: $compact (button style).
--}}
@php
  $compact = $compact ?? true;
  $state = $visitor->itemReviewState();
  $awaitingIds = $visitor->idDocuments()->where('verification_status', 'pending')->orderByDesc('uploaded_at')->get();
  $awaitingDocs = \App\Models\RelationshipDocument::whereIn('document_id', $state['awaitingDocumentIds'])->get();
  $photoAwaiting = in_array('Visitor Photo', $state['awaitingReview'], true);
  $idAwaiting = in_array('Government ID', $state['awaitingReview'], true);
@endphp
@if ($state['awaitingReview'] !== [] || $state['needsReplacement'] !== [])
  <div class="req-panel" style="margin:12px 20px;" data-replacements-review>
    <div class="req-head">
      <strong>Replacements</strong>
      <span class="muted-cell">· new visits cannot be booked until these are accepted</span>
    </div>
    <ul class="req-list">
      @foreach ($state['needsReplacement'] as $label)
        <li class="req-item">
          <div class="req-main">
            <div class="req-title">{{ $label }} <span class="badge rejected"><span class="dot"></span>Waiting for the visitor</span></div>
          </div>
        </li>
      @endforeach

      @if ($photoAwaiting)
        <li class="req-item">
          <div class="req-main">
            <div class="req-title">Visitor Photo <span class="badge transferred"><span class="dot"></span>Replacement · not checked yet</span></div>
            <div class="req-meta">
              <a href="{{ $visitor->photoUrl() }}" target="_blank" rel="noopener">View photo</a>
              · taken {{ $visitor->photo_uploaded_at?->format('M j, Y H:i') }} · asked for: {{ $visitor->photo_rejection_reason }}
            </div>
          </div>
          <div class="acct-id-actions">
            @include('partials.item-reject', ['action' => route('visitor.photo.reject', $visitor->visitor_id), 'label' => 'photo', 'compact' => $compact])
          </div>
        </li>
      @endif

      @if ($idAwaiting)
        @foreach ($awaitingIds as $id)
          <li class="req-item">
            <div class="req-main">
              <div class="req-title">{{ $id->typeLabel() }} <span class="badge transferred"><span class="dot"></span>Replacement · not checked yet</span></div>
              <div class="req-meta">
                @if ($id->file_path)
                  <a href="{{ route('visitor.ids.file', [$visitor->visitor_id, $id->visitor_id_doc_id]) }}" target="_blank" rel="noopener">View ID</a> ·
                @endif
                uploaded {{ $id->uploaded_at?->format('M j, Y H:i') }}
              </div>
            </div>
            <div class="acct-id-actions">
              @include('partials.item-reject', ['action' => route('visitor.ids.reject', [$visitor->visitor_id, $id->visitor_id_doc_id]), 'label' => $id->typeLabel(), 'compact' => $compact])
            </div>
          </li>
        @endforeach
      @endif

      @foreach ($awaitingDocs as $doc)
        <li class="req-item">
          <div class="req-main">
            <div class="req-title">{{ $doc->label() }} <span class="badge transferred"><span class="dot"></span>Replacement · not checked yet</span></div>
            <div class="req-meta">
              <a href="{{ route('relationship-documents.file', $doc->document_id) }}" target="_blank" rel="noopener">View file</a>
              · uploaded {{ $doc->uploaded_at?->format('M j, Y H:i') }}
            </div>
          </div>
          <div class="acct-id-actions">
            @include('partials.item-reject', ['action' => route('relationship-documents.reject', $doc->document_id), 'label' => $doc->label(), 'compact' => $compact])
          </div>
        </li>
      @endforeach
    </ul>

    @if ($state['awaitingReview'] !== [])
      <form method="POST" action="{{ route('visitor.replacements.accept', $visitor->visitor_id) }}" style="margin-top:10px;display:flex;justify-content:flex-end;"
            data-confirm="Accept {{ implode(', ', $state['awaitingReview']) }}? Open each file first. Anything you rejected above stays rejected."
            data-confirm-title="Accept replacements" data-confirm-ok="Accept">
        @csrf
        <button type="submit" class="btn btn-blue">Accept replacements</button>
      </form>
    @endif
  </div>
@endif
