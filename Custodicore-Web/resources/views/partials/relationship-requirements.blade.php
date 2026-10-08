{{--
  BJMP requirements checklist for one visitor↔PDL relationship.
  Expects:
    $relationship — VisitorPdlRelationship (with visitor + documents loaded)
    $items        — App\Services\RelationshipRequirements::for($relationship)
  Officer actions (verify/reject files, confirmations, details) are hidden
  once the relationship is verified: its requirements are then locked.
--}}
@php
  $visitorId = $relationship->visitor_id;
  $locked = $relationship->verification_status === 'verified';
  $metCount = collect($items)->where('status', 'met')->count();
  $statusBadge = [
    'met' => ['active', 'Met'],
    'pending_review' => ['transferred', 'Awaiting review'],
    'rejected' => ['rejected', 'Rejected'],
    'missing' => ['released', 'Missing'],
    'blocked' => ['rejected', 'Blocked'],
  ];
@endphp
<div class="req-panel">
  <div class="req-head">
    <div>
      <strong>Requirements for {{ $relationship->relationshipLabel() }}</strong>
      <span class="muted-cell"> · {{ $metCount }} of {{ count($items) }} met</span>
    </div>
    @if ($metCount === count($items))
      <span class="badge active"><span class="dot"></span>All requirements met</span>
    @else
      <span class="badge transferred"><span class="dot"></span>Incomplete</span>
    @endif
  </div>

  <ul class="req-list">
    @foreach ($items as $item)
      @php [$badgeClass, $badgeText] = $statusBadge[$item['status']]; @endphp
      <li class="req-item">
        <div class="req-main">
          <div class="req-title">
            {{ $item['label'] }}
            <span class="badge {{ $badgeClass }}"><span class="dot"></span>{{ $badgeText }}</span>
          </div>
          <div class="req-desc">{{ $item['description'] }}</div>

          @if ($item['kind'] === 'document' && $item['document'])
            <div class="req-meta">
              <a href="{{ route('relationship-documents.file', $item['document']->document_id) }}" target="_blank" rel="noopener">View uploaded file</a>
              · uploaded {{ $item['document']->uploaded_at?->format('M j, Y g:i A') }}
              @if ($item['status'] === 'rejected' && $item['note'])
                <div class="req-reject-note">Rejected: {{ $item['note'] }} (waiting for the visitor to upload a new one)</div>
              @endif
            </div>
          @elseif ($item['kind'] === 'document')
            <div class="req-meta muted-cell">Not uploaded yet. The visitor uploads this from the CustodiCore app.</div>
          @elseif ($item['kind'] === 'valid_id' && $item['status'] !== 'met')
            <div class="req-meta muted-cell">Review it under Identity Documents on the visitor's page.</div>
          @elseif ($item['kind'] === 'confirmation' && $item['status'] === 'met' && $item['note'])
            <div class="req-meta">Recorded: {{ $item['note'] }}</div>
          @endif
        </div>

        @unless ($locked)
          <div class="req-actions">
            @if ($item['kind'] === 'document' && $item['status'] === 'pending_review')
              <form method="POST" action="{{ route('relationship-documents.verify', $item['document']->document_id) }}">
                @csrf
                <button type="submit" class="row-action" style="color:var(--green);">Verify</button>
              </form>
              <details class="req-inline">
                <summary class="row-action" style="color:var(--red);">Reject</summary>
                <form method="POST" action="{{ route('relationship-documents.reject', $item['document']->document_id) }}" class="req-inline-form">
                  @csrf
                  <input type="text" name="rejection_reason" maxlength="500" required placeholder="Reason shown to the visitor">
                  <button type="submit" class="btn btn-outline-neutral" style="padding:7px 12px;">Reject</button>
                </form>
              </details>
            @elseif ($item['kind'] === 'confirmation' && $item['status'] !== 'met')
              <form method="POST" action="{{ route('visitor.relationships.confirm', [$visitorId, $relationship->relationship_id, $item['key']]) }}" class="req-inline-form">
                @csrf
                @if ($item['key'] === 'accompanying_guardian')
                  <input type="text" name="note" maxlength="150" required placeholder="Name of parent/guardian">
                @endif
                <button type="submit" class="btn btn-outline-neutral" style="padding:7px 12px;">Confirm</button>
              </form>
            @elseif ($item['kind'] === 'confirmation')
              <form method="POST" action="{{ route('visitor.relationships.unconfirm', [$visitorId, $relationship->relationship_id, $item['key']]) }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="row-action" style="color:var(--muted);">Undo</button>
              </form>
            @elseif ($item['key'] === 'children_together')
              <form method="POST" action="{{ route('visitor.relationships.update', [$visitorId, $relationship->relationship_id]) }}" class="req-inline-form">
                @csrf
                @method('PATCH')
                <input type="hidden" name="relationship_type" value="{{ $relationship->relationship_type }}">
                <select name="has_children_together" required>
                  <option value="">Select…</option>
                  <option value="1">Yes, they have children</option>
                  <option value="0">No children</option>
                </select>
                <button type="submit" class="btn btn-outline-neutral" style="padding:7px 12px;">Save</button>
              </form>
            @endif
          </div>
        @endunless
      </li>
    @endforeach
  </ul>

  @if ($relationship->supporting_document_path)
    <p class="req-meta" style="margin:8px 0 0 0;">
      Earlier upload (before the requirements checklist):
      <a href="{{ route('visitor.relationships.legacy-file', [$visitorId, $relationship->relationship_id]) }}" target="_blank" rel="noopener">View file</a>
    </p>
  @endif

  {{-- Relationship details drive the checklist; changing them on a verified relationship sends it back to pending. --}}
  <details class="req-details" {{ $relationship->isLegacyType() ? 'open' : '' }}>
    <summary class="row-action">Change relationship details</summary>
    <form method="POST" action="{{ route('visitor.relationships.update', [$visitorId, $relationship->relationship_id]) }}" class="req-inline-form" style="margin-top:10px;">
      @csrf
      @method('PATCH')
      <select name="relationship_type" required data-rel-type>
        @if ($relationship->isLegacyType())
          <option value="">Select the specific relationship…</option>
        @endif
        @foreach (\App\Models\VisitorPdlRelationship::RELATIONSHIP_TYPES as $type)
          <option value="{{ $type }}" {{ $relationship->relationship_type === $type ? 'selected' : '' }}>{{ \App\Models\VisitorPdlRelationship::TYPE_LABELS[$type] }}</option>
        @endforeach
      </select>
      <select name="has_children_together" data-children-select>
        <option value="">Children together: not recorded</option>
        <option value="1" {{ $relationship->has_children_together === true ? 'selected' : '' }}>Has children together</option>
        <option value="0" {{ $relationship->has_children_together === false ? 'selected' : '' }}>No children together</option>
      </select>
      <button type="submit" class="btn btn-outline-neutral" style="padding:7px 12px;">Save details</button>
    </form>
    @if ($locked)
      <p class="muted-cell" style="margin:6px 0 0 0;font-size:12px;">This relationship is verified. Changing its details sends it back to pending.</p>
    @endif
  </details>
</div>

@once
<script>
  // "Children together" only applies to live-in partners.
  document.addEventListener('change', function (e) {
    if (!e.target.matches('[data-rel-type]')) return;
    var children = e.target.form.querySelector('[data-children-select]');
    if (children) children.style.display = e.target.value === 'live_in_partner' ? '' : 'none';
  });
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-rel-type]').forEach(function (sel) {
      var children = sel.form.querySelector('[data-children-select]');
      if (children) children.style.display = sel.value === 'live_in_partner' ? '' : 'none';
    });
  });
</script>
@endonce
