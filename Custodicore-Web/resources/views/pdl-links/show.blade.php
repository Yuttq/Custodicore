@extends('layouts.app')
@section('title', 'PDL Request — CustodiCore')

{{--
  Phase 5B, Stage 3: one visitor request to add another PDL. Staff read the
  request and its documents (view only — the files are never changed here),
  then match it to the actual PDL or reject it. A linked relationship is
  reviewed and approved on the visitor page as usual.
    $pdlRequest — PdlLinkRequest with visitor.account, documents, reviewer, relationship.pdl
--}}
@php
  $visitor = $pdlRequest->visitor;
  $statusLabels = ['open' => 'Waiting for review', 'linked' => 'Linked', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled by visitor'];
  $canLink = $pdlRequest->isOpen() && $visitor?->verification_status === 'verified';
  $oldType = old('relationship_type', $pdlRequest->relationship_type);
  $oldChildren = old('has_children_together', $pdlRequest->has_children_together === null ? '' : ($pdlRequest->has_children_together ? '1' : '0'));
@endphp

@section('content')
<div class="topbar">
  <div>
    <h1 style="margin:0;">PDL Request</h1>
    <div class="muted-cell">{{ $visitor?->full_name ?? 'Visitor' }} asks to add “{{ $pdlRequest->pdl_name }}” · {{ $statusLabels[$pdlRequest->status] ?? $pdlRequest->status }}</div>
  </div>
  <div class="right">
    <a class="btn btn-outline-neutral" href="{{ route('pdl-links.index') }}">Back to PDL Links</a>
  </div>
</div>

<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Request</h2></div>
  <div class="row cols-3" style="margin-bottom:0;">
    <div><div class="stat-label">PDL name given by the visitor</div><div style="font-weight:600;">{{ $pdlRequest->pdl_name }}</div></div>
    <div><div class="stat-label">Relationship requested</div><div>{{ $pdlRequest->relationshipLabel() }}</div></div>
    <div><div class="stat-label">Submitted</div><div>{{ $pdlRequest->created_at?->format('M j, Y H:i') ?? '—' }}</div></div>
  </div>
  @if ($pdlRequest->relationship_type === 'live_in_partner')
    <div class="row" style="margin-top:16px;margin-bottom:0;">
      <div><div class="stat-label">Children together</div>
        <div>{{ $pdlRequest->has_children_together === null ? 'Not answered' : ($pdlRequest->has_children_together ? 'Yes, they have children together' : 'No children together') }}</div>
      </div>
    </div>
  @endif
  @if (! $pdlRequest->isOpen())
    <div class="row" style="margin-top:16px;margin-bottom:0;">
      <div>
        <div class="stat-label">Outcome</div>
        <div>
          {{ $statusLabels[$pdlRequest->status] ?? $pdlRequest->status }}
          @if ($pdlRequest->reviewed_at) · {{ $pdlRequest->reviewed_at->format('M j, Y H:i') }} @endif
          @if ($pdlRequest->reviewer) · by {{ $pdlRequest->reviewer->full_name ?? 'staff' }} @endif
        </div>
        @if ($pdlRequest->status === 'rejected' && $pdlRequest->rejection_reason)
          <div class="muted-cell">Reason: {{ $pdlRequest->rejection_reason }}</div>
        @endif
        @if ($pdlRequest->status === 'linked' && $pdlRequest->relationship)
          <div class="muted-cell">
            Linked to {{ $pdlRequest->relationship->pdl?->full_name ?? 'PDL' }} ({{ $pdlRequest->relationship->relationshipLabel() }}).
            <a href="{{ route('visitor.show', $pdlRequest->visitor_id) }}#relationships">Open the visitor page</a> to review it.
          </div>
        @endif
      </div>
    </div>
  @endif
</div>

<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Visitor</h2></div>
  @if ($visitor)
    <div class="row cols-3" style="margin-bottom:0;">
      <div><div class="stat-label">Name</div><div><a href="{{ route('visitor.show', $visitor->visitor_id) }}">{{ $visitor->full_name }}</a></div></div>
      <div><div class="stat-label">Date of Birth</div><div>{{ $visitor->date_of_birth?->format('M d, Y') ?? '—' }}</div></div>
      <div><div class="stat-label">Contact Number</div><div>{{ $visitor->contact_number ?? '—' }}</div></div>
    </div>
    <div class="row cols-2" style="margin-top:16px;margin-bottom:0;">
      <div><div class="stat-label">Email</div><div>{{ $visitor->account?->email ?? '—' }}</div></div>
      <div><div class="stat-label">Account</div><div>{{ ['verified' => 'Approved', 'pending' => 'Pending review', 'rejected' => 'Returned / not approved'][$visitor->verification_status] ?? $visitor->verification_status }}</div></div>
    </div>
  @else
    <p class="empty-note">This visitor no longer exists.</p>
  @endif
</div>

<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Submitted Documents</h2></div>
  @if ($pdlRequest->documents->isEmpty())
    <p class="empty-note">
      @if (in_array($pdlRequest->status, ['rejected', 'cancelled'], true))
        The documents of a {{ $pdlRequest->status }} request are deleted.
      @else
        No documents on file.
      @endif
    </p>
  @else
    <ul class="req-list">
      @foreach ($pdlRequest->documents as $doc)
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
      View only. Each document is approved or rejected on the relationship's checklist after you link the PDL.
    </p>
  @endif
</div>

@if ($pdlRequest->isOpen())
<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Link to the Actual PDL</h2></div>
  @if (! $canLink)
    <p class="empty-note">This visitor's account is not approved right now, so the PDL cannot be linked. You can still reject the request.</p>
  @else
    <form method="POST" action="{{ route('pdl-requests.link', $pdlRequest->request_id) }}"
          data-confirm="Link this request to the selected PDL and approve it? This approves the documents the visitor sent and the relationship in one step. If something only an officer can confirm is missing, it stays pending."
          data-confirm-title="Link & Approve" data-confirm-ok="Link & Approve">
      @csrf
      <div class="row cols-3">
        <div class="field-m pdl-search" data-pdl-search data-include-related="1" data-url="{{ route('visitor.relatable-pdls', $visitor->visitor_id) }}">
          <label for="pdlSearchInput">PDL</label>
          <input type="hidden" name="pdl_id" value="" data-pdl-id>
          <div class="pdl-search-box">
            <input type="text" id="pdlSearchInput" name="pdl_search" autocomplete="off" data-label="PDL"
                   role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="pdlSearchResults"
                   placeholder="Search name, alias, PDL number or ID" value="">
            <ul class="pdl-search-results" id="pdlSearchResults" role="listbox" hidden></ul>
          </div>
          <p class="field-hint" data-pdl-hint>Type at least 1 character, then pick the PDL from the list.</p>
        </div>
        <div class="field-m">
          <label>Relationship Type</label>
          <select name="relationship_type" required>
            @foreach (\App\Models\VisitorPdlRelationship::RELATIONSHIP_TYPES as $type)
              <option value="{{ $type }}" {{ $oldType === $type ? 'selected' : '' }}>{{ \App\Models\VisitorPdlRelationship::TYPE_LABELS[$type] }}</option>
            @endforeach
          </select>
        </div>
        <div class="field-m">
          <label>Children together (live-in partners)</label>
          <select name="has_children_together">
            <option value="" {{ $oldChildren === '' ? 'selected' : '' }}>Not applicable</option>
            <option value="1" {{ (string) $oldChildren === '1' ? 'selected' : '' }}>Has children together</option>
            <option value="0" {{ (string) $oldChildren === '0' ? 'selected' : '' }}>No children together</option>
          </select>
        </div>
      </div>
      <div class="muted-cell" style="margin-bottom:12px;font-size:12px;">
        The visitor's answers are pre-filled; correct them if the documents show otherwise. No existing link: a new pending
        relationship is created. Pending link: it is reused. Rejected link: it is reopened as pending, its rejected documents are
        deleted (approved ones are kept). An already approved link cannot be linked again. The request's documents move to the
        relationship and are approved with it, so nothing needs approving again on the visitor page.
      </div>
      <button class="btn btn-blue" type="submit">Link &amp; Approve</button>
    </form>
  @endif
</div>

<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Reject Request</h2></div>
  <form method="POST" action="{{ route('pdl-requests.reject', $pdlRequest->request_id) }}"
        data-confirm="Reject this request? The visitor will see your reason, and the request's documents will be deleted."
        data-confirm-title="Reject request" data-confirm-ok="Reject" data-confirm-danger>
    @csrf
    <div class="field-m">
      <label for="pdlRequestReason">Reason (shown to the visitor)</label>
      <textarea id="pdlRequestReason" name="rejection_reason" required minlength="5" maxlength="500" data-label="Reason (shown to the visitor)"
                placeholder="e.g. No PDL with this name is in our custody. Check the full name and send a new request.">{{ old('rejection_reason') }}</textarea>
    </div>
    <button type="submit" class="btn btn-outline-neutral" style="color:var(--red);">Reject request</button>
  </form>
</div>

@include('partials.pdl-search-script')
@endif
@endsection
