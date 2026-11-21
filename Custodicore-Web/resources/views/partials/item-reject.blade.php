{{--
  One compact Reject control for one submitted item (photo, government ID,
  registration or relationship file). Reject only: items are approved with
  the final Approve (or Accept replacements for an approved visitor).

  Usage:
    @include('partials.item-reject', [
      'action' => route(...),            // POST target
      'label' => 'National ID',          // what is rejected, for the pop-up
      'approved' => false,               // true: the item was accepted → "Request replacement"
      'compact' => true,                 // button style (Review Queue card); false: row link (Visitor Details)
      'field' => 'rejection_reason',     // optional input name
      'title' => null,                   // optional summary text override
      'notify' => true,                  // false: staff note only (Set aside duplicate)
    ])
--}}
@php
  $approved = $approved ?? false;
  $compact = $compact ?? true;
  $field = $field ?? 'rejection_reason';
  $notify = $notify ?? true;
  $title = $title ?? ($approved ? 'Request replacement' : 'Reject');
  $confirm = $notify
    ? ($approved
        ? "Ask the visitor for a new {$label}? They are notified with your reason and can replace only this item. An approved visitor cannot book new visits until it is accepted."
        : "Reject the {$label}? The visitor is notified with your reason and can replace only this item. Nothing else changes.")
    : "{$title}: {$label}? It is kept in the records and is not approved. The visitor is not notified.";
  $hint = $notify ? 'Reason (shown to the visitor)' : 'Reason (staff note)';
@endphp
<details class="acct-reject">
  <summary class="{{ $compact ? 'btn btn-outline-neutral acct-small elig-reject' : 'row-action' }}" @unless ($compact) style="cursor:pointer;color:var(--red);" @endunless>{{ $title }}</summary>
  <form method="POST" action="{{ $action }}" class="acct-reject-form"
        data-confirm="{{ $confirm }}" data-confirm-title="{{ $title }}" data-confirm-ok="{{ $title }}" data-confirm-danger>
    @csrf
    <textarea name="{{ $field }}" required minlength="5" maxlength="500" aria-label="{{ $hint }}" data-label="{{ $hint }}"
              placeholder="{{ $notify ? 'e.g. The picture is blurry. Please upload a clear one.' : 'e.g. Duplicate upload of the same ID.' }}"></textarea>
    <button type="submit" class="{{ $compact ? 'btn btn-outline-neutral acct-small elig-reject' : 'row-action' }}" @unless ($compact) style="color:var(--red);" @endunless>Confirm</button>
  </form>
</details>
