{{--
  An ID's expiry badge and, for the ID in use, the form to record it.
    $doc     — VisitorId
    $visitor — VisitorProfile
    $editable — show the "Set expiry" form (the current, pending or approved ID)
--}}
@php
  $expiryState = $doc->expiryState();
  $expiryBadge = ['expired' => 'rejected', 'soon' => 'transferred', 'valid' => 'active', 'none' => 'active', 'unknown' => 'released'][$expiryState];
@endphp
<span class="badge {{ $expiryBadge }}"><span class="dot"></span>{{ $doc->expiryLabel() }}</span>
@if ($expiryState === 'expired')
  <div class="muted-cell" style="margin-top:2px;color:var(--red);">Cannot book visits until a new ID is approved.</div>
@endif
@if ($editable ?? false)
  <details class="id-expiry-edit" @if ($errors->has('id_expiry_date') && (string) old('id_expiry_doc') === (string) $doc->visitor_id_doc_id) open @endif>
    <summary class="row-action">{{ $expiryState === 'unknown' ? 'Record expiry' : 'Change expiry' }}</summary>
    <form method="POST" action="{{ route('visitor.ids.expiry', [$visitor->visitor_id, $doc->visitor_id_doc_id]) }}" class="id-expiry-form" data-id-expiry data-id-scan-box>
      @csrf
      @if ($doc->file_path)
        <div class="id-scan-row">
          <button type="button" class="btn btn-outline-neutral" data-id-scan data-src="{{ route('visitor.ids.file', [$visitor->visitor_id, $doc->visitor_id_doc_id]) }}">Scan ID</button>
          <span class="id-scan-status" data-id-scan-status aria-live="polite">Reads the expiry date off the ID picture.</span>
        </div>
      @endif
      <input type="hidden" name="id_expiry_doc" value="{{ $doc->visitor_id_doc_id }}">
      <label class="muted-cell" for="id-expiry-{{ $doc->visitor_id_doc_id }}">Valid until (as printed on the ID)</label>
      <input type="date" id="id-expiry-{{ $doc->visitor_id_doc_id }}" name="id_expiry_date"
             value="{{ old('id_expiry_date', $doc->expiry_date?->toDateString()) }}" @disabled($doc->no_expiry) data-id-expiry-date>
      <label class="id-expiry-none">
        <input type="checkbox" name="id_no_expiry" value="1" @checked($doc->no_expiry) data-id-no-expiry> No expiry date on this ID
      </label>
      @error('id_expiry_date')<div class="field-error">{{ $message }}</div>@enderror
      <button type="submit" class="btn btn-blue">Save</button>
    </form>
  </details>
@endif
@include('partials.id-expiry-script')
