{{--
  Case number + offense (from App\Support\Offenses, or "Other" with own
  wording) + court. Used on Register PDL and "Add Legal Record".
    $showClassification — true on Register PDL: shows the classification the
                          chosen offense will give the PDL (and its visiting days)
--}}
@php
  use App\Support\Offenses;
  $showClassification = $showClassification ?? false;
  $oldCode = old('offense_code');
@endphp
<div class="case-fields" data-case-fields>
  <div class="row cols-3">
    <div class="field-m">
      <label>Case Number</label>
      <input type="text" name="case_number" value="{{ old('case_number') }}" required maxlength="50"
             pattern="[A-Za-z0-9][A-Za-z0-9 .\/\-]*" title="Letters, numbers, spaces, dots, slashes and dashes only"
             placeholder="e.g. CR-2026-0142">
    </div>
    <div class="field-m">
      <label>Offense</label>
      <select name="offense_code" required data-offense-select>
        <option value="">— Choose the offense —</option>
        @foreach (Offenses::GROUPS as $group => $offenses)
          <optgroup label="{{ $group }}">
            @foreach ($offenses as $code => [$label, $isDrug])
              <option value="{{ $code }}" data-drug="{{ $isDrug ? '1' : '0' }}" {{ $oldCode === $code ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
          </optgroup>
        @endforeach
        <option value="{{ Offenses::OTHER }}" data-drug="0" {{ $oldCode === Offenses::OTHER ? 'selected' : '' }}>Other (type it in)</option>
      </select>
    </div>
    <div class="field-m">
      <label>Court (optional)</label>
      <input type="text" name="court" value="{{ old('court') }}" maxlength="150" placeholder="e.g. RTC Branch 12, Quezon City">
    </div>
  </div>
  <div class="row" data-offense-other {{ $oldCode === Offenses::OTHER ? '' : 'hidden' }}>
    <div class="field-m">
      <label>Offense (as written in the case)</label>
      <input type="text" name="offense_other" value="{{ old('offense_other') }}" minlength="3" maxlength="255"
             placeholder="e.g. Reckless imprudence resulting in homicide" {{ $oldCode === Offenses::OTHER ? 'required' : '' }}>
      <p class="field-hint">Cases typed in here count as non-drug-related. If it's a drug case, pick it from the RA 9165 group instead.</p>
    </div>
  </div>
  @if ($showClassification)
    <div class="classification-preview" data-classification-preview hidden>
      Classification: <strong data-classification-label></strong>
      <span class="muted-cell" data-classification-days></span>
    </div>
  @endif
</div>

@once
<script>
  document.querySelectorAll('[data-case-fields]').forEach(function (box) {
    var select = box.querySelector('[data-offense-select]');
    var otherRow = box.querySelector('[data-offense-other]');
    var otherInput = otherRow.querySelector('input');
    var preview = box.querySelector('[data-classification-preview]');

    function update() {
      var opt = select.options[select.selectedIndex];
      var isOther = select.value === @json(\App\Support\Offenses::OTHER);
      otherRow.hidden = !isOther;
      otherInput.required = isOther;
      if (!preview) return;
      if (!select.value) { preview.hidden = true; return; }
      var drug = opt && opt.dataset.drug === '1';
      preview.hidden = false;
      preview.classList.toggle('is-drug', drug);
      preview.querySelector('[data-classification-label]').textContent = drug ? 'Drug-related' : 'Non-drug-related';
      preview.querySelector('[data-classification-days]').textContent = drug
        ? '· set automatically from the offense (RA 9165). Decides the visiting days.'
        : '· set automatically from the offense. Decides the visiting days.';
    }

    select.addEventListener('change', update);
    update();
  });
</script>
@endonce
