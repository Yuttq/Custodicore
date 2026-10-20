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
    <label for="offense">Offense</label>
    <select id="offense" name="offense">
      <option value="">All Offenses</option>
      @foreach (\App\Support\Offenses::GROUPS as $group => $offenses)
        <optgroup label="{{ $group }}">
          @foreach ($offenses as $code => [$label])
            <option value="{{ $code }}" {{ $offense === $code ? 'selected' : '' }}>{{ $label }}</option>
          @endforeach
        </optgroup>
      @endforeach
      <option value="{{ \App\Support\Offenses::OTHER }}" {{ $offense === \App\Support\Offenses::OTHER ? 'selected' : '' }}>Other offenses</option>
    </select>
  </div>

  <div class="field">
    <label for="visits">Visits</label>
    <select id="visits" name="visits">
      <option value="">Any</option>
      <option value="stale" {{ $visits === 'stale' ? 'selected' : '' }}>No visit in 30 days</option>
      <option value="never" {{ $visits === 'never' ? 'selected' : '' }}>Never visited</option>
    </select>
  </div>

  <div class="field">
    <button class="btn btn-blue" type="submit" style="width:100%;justify-content:center;">Apply Filters</button>
  </div>

  @if ($query || $status || $cellBlock || $offense || $visits)
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
        <th>PDL Number</th><th>Full Name</th><th>Gender</th><th>Offense / Classification</th>
        <th>Custody Status</th><th>Cell/Block</th><th>Admission Date</th><th>Last Visit</th>
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
            @include('pdl.partials.avatar', ['pdl' => $pdl])
            <a class="full-name" href="{{ route('pdl.show', $pdl->pdl_id) }}">
              {{ $pdl->full_name }}
              @if ($pdl->alias) <span class="muted-cell">"{{ $pdl->alias }}"</span> @endif
            </a>
          </div>
        </td>
        <td class="muted-cell">{{ $pdl->gender }}</td>
        <td>
          <div>{{ $pdl->primaryOffense() ?? '—' }}</div>
          <div class="muted-cell">{{ $pdl->classificationLabel() }}</div>
        </td>
        <td>
          <span class="badge {{ strtolower($pdl->custody_status) }}">
            <span class="dot"></span>{{ strtoupper($pdl->custody_status) }}
          </span>
        </td>
        <td class="muted-cell">{{ $pdl->cell_block ?? '—' }}</td>
        <td class="muted-cell">{{ $pdl->admission_date?->format('M d, Y') }}</td>
        <td>
          @if ($pdl->last_visit_at)
            @php $lastVisit = \Carbon\Carbon::parse($pdl->last_visit_at); @endphp
            <a class="muted-cell {{ $pdl->custody_status === 'active' && $lastVisit->lt(now()->subDays(30)) ? 'visit-stale' : '' }}"
               href="{{ route('visitation-tracking.show', $pdl->pdl_id) }}" title="{{ $lastVisit->format('M j, Y g:i A') }}">
              {{ $lastVisit->format('M d, Y') }}<br><span style="font-size:11px;">{{ $lastVisit->diffForHumans() }}</span>
            </a>
          @elseif ($pdl->custody_status === 'active')
            <span class="visit-never">Never visited</span>
          @else
            <span class="muted-cell">—</span>
          @endif
        </td>
      </tr>
      @endforeach
    </tbody>
  </table>

  <div class="table-footer">
    <span>Showing {{ $pdls->firstItem() }}–{{ $pdls->lastItem() }} of {{ $pdls->total() }} PDL records</span>
    <div class="pagination">{{ $pdls->links('partials.pagination') }}</div>
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
  <div class="stat-card accent-blue"><div class="stat-icon">👥</div><div><div class="stat-label">Total Population</div><div class="stat-value">{{ $stats['total_population'] }}</div><div class="muted-cell">in custody now</div></div></div>
  <div class="stat-card accent-navy"><div class="stat-icon">⇄</div><div><div class="stat-label">Transferred</div><div class="stat-value">{{ $stats['transferred'] }}</div></div></div>
  <div class="stat-card accent-amber"><div class="stat-icon">⚠</div><div><div class="stat-label">Deceased</div><div class="stat-value">{{ $stats['deceased'] }}</div></div></div>
  <div class="stat-card accent-gray"><div class="stat-icon">📋</div><div><div class="stat-label">Released YTD</div><div class="stat-value">{{ $stats['released_ytd'] }}</div></div></div>
</div>
@endsection
