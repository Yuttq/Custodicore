{{--
  Cell/Block dropdown. Expects:
    $cellBlocks — CellBlock collection loaded withOccupancy()
    $selected   — currently chosen block name (or null)
    $current    — the block the PDL is already in on the edit page (or null)
    $required   — browser-side required check (default true). The edit page
                  turns it off: only active PDLs need a cell, which the
                  server enforces.
  Capacity is only a recommendation (dorms are overpopulated in practice):
  a dorm over it can still be chosen, but the dropdown turns yellow and a
  warning shows the headcount. Blocks that don't match the gender chosen in
  the same form are hidden; the server re-checks (PdlController::cellBlockRule).
--}}
@php $current = $current ?? null; $required = $required ?? true; @endphp
<div class="field-m cell-block-picker" data-cell-block-picker>
  <label for="cell_block">Cell/Block</label>

  @if ($cellBlocks->isEmpty())
    <p class="field-hint">No cell blocks set up yet. <a href="{{ route('cell-blocks.index') }}">Add cell blocks</a> first.</p>
  @else
    <select id="cell_block" name="cell_block" data-cell-select {{ $required ? 'required' : '' }}>
      <option value="">— Select a cell/block —</option>
      @foreach ($cellBlocks as $block)
        @php
          $isCurrent = $current !== null && $block->name === $current;
          // Adding this PDL counts too, unless they're already in this dorm.
          $after = $block->occupied() + ($isCurrent ? 0 : 1);
          $willExceed = $after > $block->capacity;
        @endphp
        <option value="{{ $block->name }}"
                data-designation="{{ $block->designation }}"
                data-count="{{ $block->occupied() }}"
                data-capacity="{{ $block->capacity }}"
                data-exceeds="{{ $willExceed ? '1' : '0' }}"
                @selected($selected === $block->name)>
          {{ $block->name }} — {{ $block->occupied() }} {{ $block->occupied() === 1 ? 'PDL' : 'PDLs' }}@if ($block->designation !== 'any') · {{ $block->designationLabel() }}@endif @if ($isCurrent) · current @endif @if ($block->isOver()) ⚠ over recommended @endif
        </option>
      @endforeach
    </select>
    <div class="dorm-warning" data-dorm-warning hidden role="status"></div>
  @endif
</div>

@once
<script>
  document.querySelectorAll('[data-cell-block-picker]').forEach(function (picker) {
    var select = picker.querySelector('[data-cell-select]');
    if (!select) return;
    var form = picker.closest('form');
    var gender = form ? form.querySelector('[name="gender"]') : null;
    var warning = picker.querySelector('[data-dorm-warning]');

    // Yellow + note when the chosen dorm goes over its recommended number of PDLs.
    function warn() {
      var opt = select.options[select.selectedIndex];
      var exceeds = opt && opt.value && opt.dataset.exceeds === '1';
      select.classList.toggle('is-over', !!exceeds);
      warning.hidden = !exceeds;
      if (exceeds) {
        warning.textContent = '⚠ ' + opt.value + ' has ' + opt.dataset.count + ' PDLs; its recommended number is '
          + opt.dataset.capacity + '. You can still assign this PDL here, but the dorm will be overcrowded.';
      }
    }

    // Hide dorms that don't fit the gender picked in the same form.
    function filterGender() {
      if (!gender) return;
      Array.prototype.forEach.call(select.options, function (opt) {
        if (!opt.value) return;
        var fits = opt.dataset.designation === 'any' || opt.dataset.designation === gender.value;
        opt.hidden = !fits;
        opt.disabled = !fits;
      });
      if (select.selectedOptions[0] && select.selectedOptions[0].disabled) select.value = '';
      warn();
    }

    select.addEventListener('change', warn);
    if (gender) gender.addEventListener('change', filterGender);
    filterGender();
    warn();
  });
</script>
@endonce
