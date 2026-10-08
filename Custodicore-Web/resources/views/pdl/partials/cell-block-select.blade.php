{{--
  Cell/Block picker: one clickable card per block showing live occupancy.
  Expects:
    $cellBlocks — CellBlock collection loaded withOccupancy()
    $selected   — currently chosen block name (or null)
    $current    — the block the PDL is already in on the edit page (or null);
                  it stays selectable even when full, since the PDL is one of
                  the occupants.
    $required   — browser-side required check (default true). The edit page
                  turns it off: only active PDLs need a cell, which the
                  server enforces.
  Blocks that don't match the gender chosen in the same form are hidden, and
  full blocks can't be picked. The server re-checks both
  (PdlController::cellBlockRule).
--}}
@php $current = $current ?? null; $required = $required ?? true; @endphp
<div class="field-m cell-block-picker" data-cell-block-picker>
  <label>Cell/Block</label>

  @if ($cellBlocks->isEmpty())
    <p class="field-hint">No cell blocks set up yet. <a href="{{ route('cell-blocks.index') }}">Add cell blocks</a> first.</p>
  @else
    <p class="field-hint" data-cell-block-hint style="margin:0 0 4px 0;">Select an available cell. Full cells can't be selected.</p>
    <div class="occupancy-grid" role="radiogroup" aria-label="Cell/Block">
      @foreach ($cellBlocks as $block)
        @php
          $isCurrent = $current !== null && $block->name === $current;
          $disabled = $block->isFull() && ! $isCurrent;
          $pct = $block->capacity > 0 ? min(100, round($block->occupied() / $block->capacity * 100)) : 100;
        @endphp
        <label class="occupancy-chip cell-option {{ $block->isFull() ? 'is-full' : ($pct >= 80 ? 'is-near' : '') }}"
               data-designation="{{ $block->designation }}"
               data-full="{{ $disabled ? '1' : '0' }}">
          <input type="radio" name="cell_block" value="{{ $block->name }}"
                 {{ $selected === $block->name ? 'checked' : '' }}
                 {{ $disabled ? 'disabled' : '' }} {{ $required ? 'required' : '' }}>
          <div class="occupancy-chip-top">
            <span class="occupancy-name">{{ $block->name }}</span>
            <span class="occupancy-count">{{ $block->occupied() }}/{{ $block->capacity }}</span>
          </div>
          <div class="occupancy-bar"><span style="width:{{ $pct }}%"></span></div>
          <div class="occupancy-sub">
            {{ $block->isFull() ? 'Full' : $block->available() . ' available' }}@if ($block->designation !== 'any') · {{ $block->designationLabel() }}@endif @if ($isCurrent) · Current @endif
          </div>
        </label>
      @endforeach
    </div>
  @endif
</div>

@once
<script>
  // Hide cells that don't fit the gender picked in the same form.
  document.querySelectorAll('[data-cell-block-picker]').forEach(function (picker) {
    var form = picker.closest('form');
    var gender = form ? form.querySelector('[name="gender"]') : null;
    var hint = picker.querySelector('[data-cell-block-hint]');
    if (!gender) return;

    function apply() {
      picker.querySelectorAll('.cell-option').forEach(function (chip) {
        var fits = chip.dataset.designation === 'any' || chip.dataset.designation === gender.value;
        var radio = chip.querySelector('input');
        chip.style.display = fits ? '' : 'none';
        radio.disabled = !fits || chip.dataset.full === '1';
        if (radio.disabled) radio.checked = false;
      });

      if (hint) {
        hint.textContent = 'Select an available cell for a ' + gender.options[gender.selectedIndex].text.toLowerCase()
          + ' PDL. Full cells can’t be selected.';
      }
    }

    gender.addEventListener('change', apply);
    apply();
  });
</script>
@endonce
