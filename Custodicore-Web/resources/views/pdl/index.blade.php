@extends('layouts.app')
@section('title', 'PDL Management — CustodiCore')

@section('content')
<div class="topbar">
  <h1>PDL Management</h1>
  <div class="right">
    <a class="btn btn-primary" href="{{ route('pdl.create') }}">Register New PDL</a>
  </div>
</div>

<form class="filters" method="GET" action="{{ route('pdl.index') }}">
  <div class="field">
    <label for="q">Search Records</label>
    <div class="input">
      <input id="q" type="text" name="q" value="{{ $query }}" placeholder="Search by name, alias, or PDL number">
    </div>
  </div>

  <div class="field">
    <label for="status">Custody Status</label>
    <select id="status" name="status">
      <option value="" {{ $status === '' ? 'selected' : '' }}>All Statuses</option>
      {{-- CONFIRMED enum from the actual migration --}}
      <option value="active" {{ $status === 'active' ? 'selected' : '' }}>Active</option>
      <option value="transferred" {{ $status === 'transferred' ? 'selected' : '' }}>Transferred</option>
      <option value="released" {{ $status === 'released' ? 'selected' : '' }}>Released</option>
      <option value="deceased" {{ $status === 'deceased' ? 'selected' : '' }}>Deceased</option>
    </select>
  </div>

  <div class="field">
    <label for="cell">Cell/Block</label>
    <select id="cell" name="cell">
      <option value="">All Cells/Blocks</option>
      @foreach ($cellBlocks as $block)
        <option value="{{ $block }}" {{ $cellBlock === $block ? 'selected' : '' }}>{{ $block }}</option>
      @endforeach
    </select>
  </div>

  <div class="field">
    <button class="btn btn-blue" type="submit" style="width:100%;justify-content:center;">Apply Filters</button>
  </div>

  @if ($query || $status || $cellBlock)
    <div class="field">
      <a class="btn btn-outline-neutral" href="{{ route('pdl.index') }}" style="width:100%;justify-content:center;">Clear</a>
    </div>
  @endif
</form>

<div class="panel-table">
  @if ($pdls->count())
  <table>
    <thead>
      <tr>
        <th>PDL Number</th><th>Full Name</th><th>Gender</th><th>Classification</th>
        <th>Custody Status</th><th>Cell/Block</th><th>Admission Date</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($pdls as $pdl)
      <tr>
        <td>
          <a href="{{ route('pdl.show', $pdl->pdl_id) }}" style="color:var(--green);font-weight:700;text-decoration:none;">
            {{ $pdl->pdl_number }}
          </a>
        </td>
        <td>
          <div class="name-cell">
            <div class="thumb">{{ $pdl->initials() }}</div>
            <a class="full-name" href="{{ route('pdl.show', $pdl->pdl_id) }}">
              {{ $pdl->full_name }}
              @if ($pdl->alias) <span class="muted-cell">"{{ $pdl->alias }}"</span> @endif
            </a>
          </div>
        </td>
        <td class="muted-cell">{{ $pdl->gender }}</td>
        <td class="muted-cell">{{ $pdl->classification ?? '—' }}</td>
        <td>
          <span class="badge {{ strtolower($pdl->custody_status) }}">
            <span class="dot"></span>{{ strtoupper($pdl->custody_status) }}
          </span>
        </td>
        <td class="muted-cell">{{ $pdl->cell_block ?? '—' }}</td>
        <td class="muted-cell">{{ $pdl->admission_date?->format('M d, Y') }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>

  <div class="table-footer">
    <span>Showing {{ $pdls->firstItem() }}–{{ $pdls->lastItem() }} of {{ $pdls->total() }} PDL records</span>
    <div class="pagination">{{ $pdls->links() }}</div>
  </div>

  @else
    <p class="empty-note" style="padding:20px;">
      No PDL records match your current search/filters.
      @if ($query || $status || $cellBlock)
        <a href="{{ route('pdl.index') }}">Clear filters</a> to see all records.
      @endif
    </p>
  @endif
</div>

<div class="stat-strip">
  <div class="stat-card accent-blue"><div class="stat-icon">👥</div><div><div class="stat-label">Total Population</div><div class="stat-value">{{ $stats['total_population'] }}</div></div></div>
  <div class="stat-card accent-navy"><div class="stat-icon">🔒</div><div><div class="stat-label">Currently Active</div><div class="stat-value">{{ $stats['currently_active'] }}</div></div></div>
  <div class="stat-card accent-amber"><div class="stat-icon">⚠</div><div><div class="stat-label">Deceased</div><div class="stat-value">{{ $stats['deceased'] }}</div></div></div>
  <div class="stat-card accent-gray"><div class="stat-icon">📋</div><div><div class="stat-label">Released YTD</div><div class="stat-value">{{ $stats['released_ytd'] }}</div></div></div>
</div>
@endsection
