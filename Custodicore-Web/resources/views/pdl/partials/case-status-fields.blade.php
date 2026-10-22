{{--
  Case status, date filed, next court hearing and, when convicted, the date of
  conviction and sentence. Used on Register PDL, Add Legal Record and each
  record's Update form.
    $record — the legal record being updated (or null for a new one)
    $useOld — fill from old() input (true when this form was the one submitted)
--}}
@php
  use App\Models\PdlLegalRecord;
  $record = $record ?? null;
  $useOld = $useOld ?? true;
  $value = fn (string $key, $fallback = null) => $useOld ? old($key, $fallback) : $fallback;
  $status = $value('case_status', $record?->case_status ?? PdlLegalRecord::PENDING);
  $next = $record?->nextHearing();
@endphp
<div class="case-status-fields" data-case-status>
  <div class="row cols-3">
    <div class="field-m">
      <label>Case Status</label>
      <select name="case_status" required data-case-status-select>
        @foreach (PdlLegalRecord::STATUSES as $option)
          <option value="{{ $option }}" @selected($status === $option)>{{ $option }}</option>
        @endforeach
      </select>
    </div>
    <div class="field-m">
      <label>Date Filed / Commitment (optional)</label>
      <input type="date" name="date_filed" value="{{ $value('date_filed', $record?->date_filed?->toDateString()) }}" max="{{ now()->toDateString() }}">
    </div>
    <div class="field-m" data-hearing-field>
      <label>Next Court Hearing (optional)</label>
      <input type="datetime-local" name="next_hearing_at" value="{{ $value('next_hearing_at') }}" min="{{ now()->startOfDay()->format('Y-m-d\TH:i') }}">
      @if ($next)
        <p class="field-hint">Currently set: {{ $next->hearing_at->format('D, M j, Y H:i') }}. Enter a new date to add the next one.</p>
      @endif
    </div>
  </div>

  <div class="row cols-3" data-hearing-field>
    <div class="field-m" style="grid-column:span 3;">
      <label>Hearing Notes (optional)</label>
      <input type="text" name="hearing_notes" value="{{ $value('hearing_notes') }}" maxlength="255" placeholder="e.g. Arraignment, pre-trial, promulgation">
    </div>
  </div>

  {{-- Only for Convicted / Convicted, on appeal. --}}
  <div class="conviction-fields" data-conviction-fields>
    <p class="field-hint" style="margin:0 0 8px;font-weight:700;">Conviction</p>
    <div class="row cols-3">
      <div class="field-m">
        <label>Date of Conviction</label>
        <input type="date" name="convicted_on" value="{{ $value('convicted_on', $record?->convicted_on?->toDateString()) }}" max="{{ now()->toDateString() }}">
      </div>
      <div class="field-m">
        <label>Sentence</label>
        <div class="sentence-inputs">
          <label><input type="number" name="sentence_years" min="0" max="99" value="{{ $value('sentence_years', $record?->sentence_years) }}"> years</label>
          <label><input type="number" name="sentence_months" min="0" max="11" value="{{ $value('sentence_months', $record?->sentence_months) }}"> months</label>
          <label><input type="number" name="sentence_days" min="0" max="30" value="{{ $value('sentence_days', $record?->sentence_days) }}"> days</label>
        </div>
      </div>
    </div>
  </div>
</div>

@once
<script>
  // Conviction fields only for convicted cases; no hearings on acquitted or dismissed ones.
  // Runs after the page loads: this partial appears several times (Add form + each Update window).
  document.addEventListener('DOMContentLoaded', function () { document.querySelectorAll('[data-case-status]').forEach(function (box) {
    var select = box.querySelector('[data-case-status-select]');
    var conviction = box.querySelector('[data-conviction-fields]');
    var convictedOn = conviction.querySelector('[name="convicted_on"]');
    var convicted = @json(\App\Models\PdlLegalRecord::CONVICTED_STATUSES);
    var closed = @json(\App\Models\PdlLegalRecord::CLOSED_STATUSES);

    function update() {
      var isConvicted = convicted.indexOf(select.value) !== -1;
      conviction.hidden = !isConvicted;
      convictedOn.required = isConvicted;
      box.querySelectorAll('[data-hearing-field]').forEach(function (el) { el.hidden = closed.indexOf(select.value) !== -1; });
    }

    select.addEventListener('change', update);
    update();
  }); });
</script>
@endonce
