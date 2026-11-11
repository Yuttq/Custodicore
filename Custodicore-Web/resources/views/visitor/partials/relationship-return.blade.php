{{--
  "Return" for one pending visitor↔PDL relationship: sends it back with what
  to fix (RelationshipRequirementController::returnRelationship). The files
  under review are marked for replacement, the link stays pending and the
  visitor is notified. Expects $rel (with visitor, pdl) and $visitor.
--}}
@php $pdlName = $rel->pdl?->full_name ?? 'this PDL'; @endphp
<details class="acct-reject">
  <summary class="row-action" style="cursor:pointer;color:var(--amber-text);">Return</summary>
  <form method="POST" action="{{ route('visitor.relationships.return', [$visitor->visitor_id, $rel->relationship_id]) }}" class="acct-reject-form"
        data-confirm="Return {{ $visitor->full_name }}'s link to {{ $pdlName }}? They see what to fix in the app and send corrected documents. The link stays pending; nothing is approved or rejected."
        data-confirm-title="Return for changes" data-confirm-ok="Return">
    @csrf
    <textarea name="rejection_reason" required minlength="5" maxlength="500" aria-label="What to fix (shown to the visitor)"
              data-label="What to fix (shown to the visitor)"
              placeholder="e.g. The guardianship proof is unreadable. Please upload a clearer copy."></textarea>
    <button type="submit" class="row-action" style="color:var(--amber-text);">Send back</button>
  </form>
</details>
