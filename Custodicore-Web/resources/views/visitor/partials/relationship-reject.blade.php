{{--
  "Reject" (pending) or "Revoke Approval" (approved) for one visitor↔PDL
  relationship (visitor/show). Expects $rel and $visitor. Both post to
  visitor.relationships.reject. The reason goes to the visitor, who is told
  to send a new Add Another PDL request; nothing else on the account changes.
--}}
@php
  $revoke = $rel->verification_status === 'verified';
  $pdlName = $rel->pdl?->full_name ?? 'this PDL';
  $label = $revoke ? 'Revoke Approval' : 'Reject';
  $reasonLabel = $revoke ? 'Why the approval is revoked (shown to the visitor)' : 'Why the relationship was rejected (shown to the visitor)';
  $message = $revoke
      ? "Revoke this approved relationship? The visitor will no longer be able to visit {$pdlName}. They will be asked to send a new request with corrected documents. Their account and other PDLs are not affected."
      : "Reject this relationship? The visitor will not be able to visit {$pdlName}."
        . ($visitor->verification_status === 'verified' ? ' They will be asked to send a new request with corrected documents. Their account and other PDLs are not affected.' : '');
@endphp
<details class="acct-reject">
  <summary class="row-action" style="cursor:pointer;color:var(--red);">{{ $label }}</summary>
  <form method="POST" action="{{ route('visitor.relationships.reject', [$visitor->visitor_id, $rel->relationship_id]) }}" class="acct-reject-form"
        data-confirm="{{ $message }}"
        data-confirm-title="{{ $revoke ? 'Revoke approval' : 'Reject relationship' }}" data-confirm-ok="{{ $revoke ? 'Revoke' : 'Reject' }}" data-confirm-danger>
    @csrf
    <textarea name="rejection_reason" required minlength="5" maxlength="500" aria-label="{{ $reasonLabel }}"
              data-label="{{ $reasonLabel }}"
              placeholder="{{ $revoke ? 'e.g. The marriage certificate was found to be false.' : 'e.g. The names on the marriage certificate do not match.' }}"></textarea>
    <button type="submit" class="row-action" style="color:var(--red);">Confirm {{ $label }}</button>
  </form>
</details>
