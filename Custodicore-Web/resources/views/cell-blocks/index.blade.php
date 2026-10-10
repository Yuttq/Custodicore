@extends('layouts.app')
@section('title', 'Cell Blocks — CustodiCore')

@section('content')
<div class="topbar">
  <h1>Cell Blocks</h1>
  <div class="right">
    <button type="button" class="btn btn-primary" onclick="openCellBlockModal()">Add Cell Block</button>
  </div>
</div>

<div class="stat-strip" style="margin-bottom:20px;">
  <div class="stat-card"><div class="stat-label">Active Blocks</div><div class="stat-value">{{ $stats['blocks'] }}</div></div>
  <div class="stat-card"><div class="stat-label">Total Capacity</div><div class="stat-value">{{ $stats['capacity'] }}</div></div>
  <div class="stat-card"><div class="stat-label">Occupied Beds</div><div class="stat-value">{{ $stats['occupied'] }}</div></div>
  <div class="stat-card"><div class="stat-label">Full Blocks</div><div class="stat-value">{{ $stats['full'] }}</div></div>
</div>

<div class="panel-table">
  @if ($cellBlocks->isEmpty())
    <p class="empty-note" style="padding:24px;">No cell blocks yet. Add one so PDLs can be assigned to it.</p>
  @else
  <table>
    <thead>
      <tr><th>Cell/Block</th><th>Designated For</th><th>Occupancy</th><th>Status</th><th></th></tr>
    </thead>
    <tbody>
      @foreach ($cellBlocks as $block)
        @php $pct = min(100, round($block->occupied() / max(1, $block->capacity) * 100)); @endphp
        <tr>
          <td><strong>{{ $block->name }}</strong></td>
          <td class="muted-cell">{{ $block->designationLabel() }}</td>
          <td>
            <div class="occupancy-chip occupancy-inline {{ $block->isFull() ? 'is-full' : ($pct >= 80 ? 'is-near' : '') }}" style="background:none;border:none;padding:0;">
              <div class="occupancy-bar"><span style="width:{{ $pct }}%"></span></div>
              <span class="occupancy-count">{{ $block->occupied() }}/{{ $block->capacity }}</span>
            </div>
          </td>
          <td>
            @if (! $block->is_active)
              <span class="badge released"><span class="dot"></span>Inactive</span>
            @elseif ($block->isFull())
              <span class="badge rejected"><span class="dot"></span>Full</span>
            @else
              <span class="badge active"><span class="dot"></span>{{ $block->available() }} available</span>
            @endif
          </td>
          <td style="text-align:right;">
            <a class="row-action" href="{{ route('pdl.index', ['cell' => $block->name]) }}" style="margin-right:14px;">View PDLs</a>
            @php
              $editPayload = [
                  'action' => route('cell-blocks.update', $block->cell_block_id),
                  'name' => $block->name,
                  'capacity' => $block->capacity,
                  'designation' => $block->designation,
                  'is_active' => $block->is_active,
                  'occupied' => $block->occupied(),
              ];
            @endphp
            <button type="button" class="row-action" onclick='openCellBlockModal(@json($editPayload))'>Edit</button>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  @endif
</div>

<p class="muted-cell">Occupancy counts PDLs with custody status <strong>Active</strong>. Released, transferred and deceased PDLs free up their bed automatically.</p>

{{-- Add / edit modal (one form, switched between POST and PUT) --}}
<div class="modal-overlay" id="cellBlockModal" onclick="if (event.target === this) closeCellBlockModal()">
  <div class="modal modal-narrow">
    <form method="POST" id="cellBlockForm" action="{{ route('cell-blocks.store') }}"
          data-confirm="Save this cell block?" data-confirm-title="Save cell block" data-confirm-ok="Save">
      @csrf
      <input type="hidden" name="_method" id="cellBlockMethod" value="POST">
      <div class="modal-header">
        <div>
          <h2 id="cellBlockTitle">Add Cell Block</h2>
          <div class="sub" id="cellBlockSub">Blocks appear in the Cell/Block dropdown when registering a PDL.</div>
        </div>
        <button type="button" class="close" onclick="closeCellBlockModal()">✕</button>
      </div>
      <div class="modal-body">
        <div class="row cols-2">
          <div class="field-m">
            <label for="cb_name">Name</label>
            <input id="cb_name" type="text" name="name" maxlength="50" required placeholder="e.g. Dorm 6">
          </div>
          <div class="field-m">
            <label for="cb_capacity">Capacity (beds)</label>
            <input id="cb_capacity" type="number" name="capacity" min="1" max="1000" required>
          </div>
        </div>
        <div class="row cols-2">
          <div class="field-m">
            <label for="cb_designation">Designated For</label>
            <select id="cb_designation" name="designation">
              <option value="any">Any</option>
              <option value="male">Male only</option>
              <option value="female">Female only</option>
            </select>
          </div>
          <div class="field-m" id="cbActiveField" style="display:none;">
            <label>Status</label>
            <label style="display:flex;align-items:center;gap:8px;font-weight:500;font-size:13px;margin-top:10px;">
              <input type="hidden" name="is_active" value="0">
              <input type="checkbox" id="cb_is_active" name="is_active" value="1" style="width:auto;">
              Active (can receive new PDLs)
            </label>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-neutral" onclick="closeCellBlockModal()">Cancel</button>
        <button type="submit" class="btn btn-blue" id="cellBlockSubmit">Add Cell Block</button>
      </div>
    </form>
  </div>
</div>

<script>
  function openCellBlockModal(block) {
    var form = document.getElementById('cellBlockForm');
    var editing = !!block;
    form.action = editing ? block.action : @json(route('cell-blocks.store'));
    document.getElementById('cellBlockMethod').value = editing ? 'PUT' : 'POST';
    document.getElementById('cellBlockTitle').textContent = editing ? 'Edit ' + block.name : 'Add Cell Block';
    document.getElementById('cellBlockSub').textContent = editing
      ? block.occupied + ' active PDL(s) in this block. Capacity can’t go below that.'
      : 'Blocks appear in the Cell/Block dropdown when registering a PDL.';
    document.getElementById('cellBlockSubmit').textContent = editing ? 'Save Changes' : 'Add Cell Block';
    document.getElementById('cb_name').value = editing ? block.name : '';
    document.getElementById('cb_capacity').value = editing ? block.capacity : '';
    document.getElementById('cb_capacity').min = editing ? Math.max(1, block.occupied) : 1;
    document.getElementById('cb_designation').value = editing ? block.designation : 'any';
    document.getElementById('cbActiveField').style.display = editing ? '' : 'none';
    document.getElementById('cb_is_active').checked = editing ? block.is_active : true;
    document.getElementById('cellBlockModal').classList.add('open');
    document.getElementById('cb_name').focus();
  }

  function closeCellBlockModal() {
    document.getElementById('cellBlockModal').classList.remove('open');
  }
</script>
@endsection
