{{--
  Cell/Block dropdown. Expects:
    $cellBlocks     — CellBlock collection loaded withOccupancy() and visitationRules
    $selected       — currently chosen block name (or null)
    $current        — the block the PDL is already in on the edit page (or null)
    $required       — browser-side required check (default true). The edit page
                      turns it off: only active PDLs need a cell, which the
                      server enforces.
    $classification — the PDL's classification on the edit page (or null). On
                      Register PDL it is read live from the offense picked above.
    $pdl            — the PDL being edited (or null): their own case numbers are
                      checked for co-accused, and they are left out of the matches.
  Capacity is only a recommendation (dorms are overpopulated in practice):
  a dorm over it can still be chosen, but the dropdown turns yellow and a
  warning shows the headcount. Blocks that don't match the gender chosen in
  the same form are hidden; the server re-checks (PdlController::cellBlockRule).
  Dorms are listed by space left; the roomiest one that fits is "Suggested".
  The card under the dropdown shows the dorm's headcount and the visiting
  schedule the PDL will get (the dorm's own hours, else the classification's).
  Co-accused: PDLs in custody on the same case number are listed, dorms holding
  one are marked and never "Suggested", and choosing such a dorm shows a
  warning (facilities usually keep co-accused apart). It is only a warning.
  Intended classification: a dorm may be meant for drug-related or
  non-drug-related cases. Shown in the list and card; "Suggested" prefers a
  matching dorm, and a mismatch only shows a note (dorms are mixed in practice).
--}}
@php
  $current = $current ?? null;
  $required = $required ?? true;
  $classification = $classification ?? null;
  $pdl = $pdl ?? null;
  $ownCases = $pdl ? $pdl->legalRecords->map(fn ($r) => \App\Models\PdlLegalRecord::normalizeCaseNumber($r->case_number))->unique()->values() : collect();
  // Most space left first; dorms already over their recommended number last.
  $ordered = $cellBlocks->sortBy(fn ($b) => [$b->isOver() ? 1 : 0, -$b->available(), $b->name])->values();
@endphp
<div class="field-m cell-block-picker" data-cell-block-picker
     data-classification="{{ $classification }}"
     data-schedule-drug="{{ \App\Models\FacilityVisitationRule::classificationSummary('drug_related') }}"
     data-schedule-nondrug="{{ \App\Models\FacilityVisitationRule::classificationSummary('non_drug_related') }}"
     data-coaccused='@json(\App\Models\PdlLegalRecord::coAccusedMap())'
     data-own-cases='@json($ownCases)'
     data-self-id="{{ $pdl?->pdl_id }}">
  <label for="cell_block">Cell/Block</label>

  @if ($cellBlocks->isEmpty())
    <p class="field-hint">No cell blocks set up yet. <a href="{{ route('cell-blocks.index') }}">Add cell blocks</a> first.</p>
  @else
    <select id="cell_block" name="cell_block" data-cell-select {{ $required ? 'required' : '' }}>
      <option value="">— Select a cell/block —</option>
      @foreach ($ordered as $block)
        @php
          $isCurrent = $current !== null && $block->name === $current;
          $count = $block->occupied();
          // Adding this PDL counts too, unless they're already in this dorm.
          $after = $count + ($isCurrent ? 0 : 1);
          $willExceed = $after > $block->capacity;
          $space = match (true) {
            $block->isOver() => '⚠ over by ' . $block->overBy(),
            $block->available() === 0 => 'full',
            default => $block->available() . ' ' . ($block->available() === 1 ? 'space' : 'spaces') . ' left',
          };
          $label = "{$block->name} — {$count}/{$block->capacity} · {$space}"
            . ($block->intended_classification ? ' · ' . ($block->intended_classification === 'drug_related' ? 'Drug-related dorm' : 'Non-drug dorm') : '')
            . ($block->designation !== 'any' ? ' · ' . $block->designationLabel() : '')
            . ($isCurrent ? ' · current' : '');
        @endphp
        <option value="{{ $block->name }}"
                data-label="{{ $label }}"
                data-designation="{{ $block->designation }}"
                data-designation-label="{{ $block->designation === 'any' ? 'Any gender' : $block->designationLabel() }}"
                data-intended="{{ $block->intended_classification }}"
                data-intended-label="{{ $block->intendedLabel() }}"
                data-count="{{ $count }}"
                data-after="{{ $after }}"
                data-capacity="{{ $block->capacity }}"
                data-exceeds="{{ $willExceed ? '1' : '0' }}"
                data-current="{{ $isCurrent ? '1' : '0' }}"
                data-schedule="{{ $block->visitingSummary() }}"
                @selected($selected === $block->name)>{{ $label }}</option>
      @endforeach
    </select>
    <div class="dorm-warning" data-dorm-warning hidden role="status"></div>
    <div class="coaccused-note" data-coaccused-note hidden role="status"></div>
    <div class="dorm-intent-note" data-intent-note hidden role="status"></div>

    <div class="dorm-card" data-dorm-card hidden>
      <div class="dorm-card-head">
        <strong data-dorm-name></strong>
        <span class="muted-cell" data-dorm-designation></span>
      </div>
      <div class="dorm-card-bar"><span data-dorm-bar></span></div>
      <div class="dorm-card-count" data-dorm-count></div>
      <div class="dorm-card-intended">Intended for: <strong data-dorm-intended></strong></div>
      <div class="dorm-card-visits">
        <span class="dorm-card-label">Visiting schedule</span>
        <span data-dorm-schedule></span>
      </div>
    </div>
  @endif
</div>

@once
<script>
  document.querySelectorAll('[data-cell-block-picker]').forEach(function (picker) {
    var select = picker.querySelector('[data-cell-select]');
    if (!select) return;
    var form = picker.closest('form');
    var gender = form ? form.querySelector('[name="gender"]') : null;
    var offense = form ? form.querySelector('[data-offense-select]') : null;
    var warning = picker.querySelector('[data-dorm-warning]');
    var card = picker.querySelector('[data-dorm-card]');
    var caseInput = form ? form.querySelector('[name="case_number"]') : null;
    var coNote = picker.querySelector('[data-coaccused-note]');
    var intentNote = picker.querySelector('[data-intent-note]');
    var classLabels = { drug_related: 'drug-related', non_drug_related: 'non-drug-related' };
    var coMap = JSON.parse(picker.dataset.coaccused || '{}');
    var ownCases = JSON.parse(picker.dataset.ownCases || '[]');
    var selfId = picker.dataset.selfId ? +picker.dataset.selfId : null;

    function normalizeCase(value) { return String(value || '').replace(/\s+/g, ' ').trim().toUpperCase(); }

    // PDLs in custody sharing a case number with this PDL (typed or on file).
    function coAccused() {
      var cases = ownCases.slice();
      if (caseInput && normalizeCase(caseInput.value)) cases.push(normalizeCase(caseInput.value));
      var seen = {}, list = [];
      cases.forEach(function (c) {
        (coMap[c] || []).forEach(function (p) {
          if (p.pdl_id === selfId || seen[p.pdl_id]) return;
          seen[p.pdl_id] = true;
          list.push({ name: p.name, dorm: p.dorm, caseNumber: c });
        });
      });
      return list;
    }

    function el(name) { return card.querySelector('[data-dorm-' + name + ']'); }

    // The PDL's classification: from the offense picked on Register PDL, else the saved one.
    function classification() {
      if (offense) {
        var opt = offense.options[offense.selectedIndex];
        if (!offense.value) return '';
        return opt && opt.dataset.drug === '1' ? 'drug_related' : 'non_drug_related';
      }
      return picker.dataset.classification || '';
    }

    function scheduleText(opt) {
      if (opt.dataset.schedule) return opt.dataset.schedule + ' (this dorm\'s own visiting hours)';
      var c = classification();
      if (c === 'drug_related') return (picker.dataset.scheduleDrug || 'No visiting days set') + ' (drug-related schedule)';
      if (c === 'non_drug_related') return (picker.dataset.scheduleNondrug || 'No visiting days set') + ' (non-drug-related schedule)';
      return 'Depends on the case. Choose the offense above to see the visiting days.';
    }

    // Yellow + note when the chosen dorm goes over its recommended number of PDLs; summary card.
    function update() {
      var opt = select.options[select.selectedIndex];
      var chosen = opt && opt.value;

      var co = coAccused();
      var sameDorm = co.filter(function (p) { return chosen && p.dorm === opt.value; });
      coNote.hidden = co.length === 0;
      coNote.classList.toggle('is-conflict', sameDorm.length > 0);
      if (co.length) {
        coNote.textContent = (sameDorm.length
            ? '⚠ Co-accused in ' + opt.value + ': ' + sameDorm.map(function (p) { return p.name + ' (case ' + p.caseNumber + ')'; }).join(', ')
              + '. Co-accused are usually kept in separate dorms. You can still save.'
            : 'Co-accused on file: ' + co.map(function (p) { return p.name + ' in ' + (p.dorm || 'no dorm') + ' (case ' + p.caseNumber + ')'; }).join(', ')
              + '. Their dorms are marked in the list.');
      }
      var exceeds = chosen && opt.dataset.exceeds === '1';
      select.classList.toggle('is-over', !!exceeds);
      warning.hidden = !exceeds;
      if (exceeds) {
        warning.textContent = '⚠ ' + opt.value + ' has ' + opt.dataset.count + ' PDLs; its recommended number is '
          + opt.dataset.capacity + '. You can still assign this PDL here, but the dorm will be overcrowded.';
      }

      // A dorm meant for the other classification: a note, never a block.
      var mine = classification();
      var mismatch = chosen && mine && opt.dataset.intended && opt.dataset.intended !== mine;
      intentNote.hidden = !mismatch;
      if (mismatch) {
        intentNote.textContent = opt.value + ' is meant for ' + classLabels[opt.dataset.intended] + ' cases; the case of this PDL is '
          + classLabels[mine] + '. You can still assign them here (dorms take both while overcrowded).';
      }

      card.hidden = !chosen;
      if (!chosen) return;
      el('intended').textContent = opt.dataset.intendedLabel;
      var after = +opt.dataset.after, capacity = +opt.dataset.capacity;
      el('name').textContent = opt.value;
      el('designation').textContent = opt.dataset.designationLabel;
      el('bar').style.width = Math.min(100, capacity ? Math.round(after / capacity * 100) : 100) + '%';
      card.classList.toggle('is-over', after > capacity);
      el('count').textContent = (opt.dataset.current === '1' ? 'Currently ' : 'With this PDL: ')
        + after + ' of ' + capacity + ' recommended'
        + (after > capacity ? ' (over by ' + (after - capacity) + ')' : ' (' + (capacity - after) + ' left)');
      el('schedule').textContent = scheduleText(opt);
    }

    // Hide dorms that don't fit the gender picked in the same form, then tag
    // "Suggested": a fitting dorm without co-accused that won't go over,
    // preferring one meant for this PDL's classification, then the most space.
    function filterGender() {
      var best = null, mine = classification();
      function rank(opt) {
        var matches = !opt.dataset.intended || !mine || opt.dataset.intended === mine;
        return [matches ? 1 : 0, +opt.dataset.capacity - +opt.dataset.count];
      }
      function better(a, b) { var ra = rank(a), rb = rank(b); return ra[0] !== rb[0] ? ra[0] > rb[0] : ra[1] > rb[1]; }
      var coDorms = coAccused().map(function (p) { return p.dorm; });
      Array.prototype.forEach.call(select.options, function (opt) {
        if (!opt.value) return;
        var fits = !gender || opt.dataset.designation === 'any' || opt.dataset.designation === gender.value;
        var hasCo = coDorms.indexOf(opt.value) !== -1;
        opt.hidden = !fits;
        opt.disabled = !fits;
        opt.textContent = opt.dataset.label + (hasCo ? ' · co-accused here' : '');
        if (fits && !hasCo && opt.dataset.exceeds !== '1' && (!best || better(opt, best))) best = opt;
      });
      if (best) best.textContent = best.textContent + '  ★ Suggested';
      if (select.selectedOptions[0] && select.selectedOptions[0].disabled) select.value = '';
      update();
    }

    select.addEventListener('change', update);
    if (gender) gender.addEventListener('change', filterGender);
    if (offense) offense.addEventListener('change', filterGender);
    if (caseInput) caseInput.addEventListener('input', filterGender);
    filterGender();
  });
</script>
@endonce
