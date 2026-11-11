{{--
  "Reject" for one visitor↔PDL relationship (visitor/show). Expects $rel and $visitor.
  The reason goes to the visitor, who is told to send a new Add Another PDL
  request; nothing else on the account changes.
--}}
<details class="acct-reject">
  <summary class="row-action" style="cursor:pointer;color:var(--red);">Reject</summary>
  <form method="POST" action="{{ route('visitor.relationships.reject', [$visitor->visitor_id, $rel->relationship_id]) }}" class="acct-reject-form"
        data-confirm="Reject this relationship? The visitor will not be able to visit {{ $rel->pdl?->full_name ?? 'this PDL' }}.{{ $visitor->verification_status === 'verified' ? ' They will be asked to send a new request with corrected documents. Their account and other PDLs are not affected.' : '' }}"
        data-confirm-title="Reject relationship" data-confirm-ok="Reject" data-confirm-danger>
    @csrf
    <textarea name="rejection_reason" required minlength="5" maxlength="500" aria-label="Why the relationship was rejected (shown to the visitor)"
              data-label="Why the relationship was rejected (shown to the visitor)"
              placeholder="e.g. The names on the marriage certificate do not match."></textarea>
    <button type="submit" class="row-action" style="color:var(--red);">Confirm Reject</button>
  </form>
</details>
