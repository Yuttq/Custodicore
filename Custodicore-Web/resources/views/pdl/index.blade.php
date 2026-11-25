@extends('layouts.app')
@section('title', 'PDL Management — CustodiCore')

@section('content')
<div class="topbar">
  <h1>PDL Management</h1>
  <div class="right">
    <a class="btn btn-primary" href="{{ route('pdl.create') }}">Register New PDL</a>
  </div>
</div>

<div class="stat-strip" style="margin-bottom:18px;">
  <a class="stat-card stat-link accent-blue" href="{{ route('pdl.index', ['status' => 'active']) }}" style="text-decoration:none;color:inherit;"><div class="stat-icon">@include('partials.icon', ['name' => 'users'])</div><div><div class="stat-label">Total Population</div><div class="stat-value">{{ $stats['total_population'] }}</div><div class="muted-cell">in custody now</div></div></a>
  <a class="stat-card stat-link accent-amber" href="{{ route('pdl.index', ['status' => 'active', 'cases' => 'drug_only']) }}" style="text-decoration:none;color:inherit;"><div class="stat-icon">@include('partials.icon', ['name' => 'scale'])</div><div><div class="stat-label">Drug Cases Only</div><div class="stat-value">{{ $stats['drug_only'] }}</div><div class="muted-cell">PDLs in custody</div></div></a>
  <a class="stat-card stat-link accent-teal" href="{{ route('pdl.index', ['status' => 'active', 'cases' => 'non_drug_only']) }}" style="text-decoration:none;color:inherit;"><div class="stat-icon">@include('partials.icon', ['name' => 'scale'])</div><div><div class="stat-label">Non-drug Cases Only</div><div class="stat-value">{{ $stats['non_drug_only'] }}</div><div class="muted-cell">PDLs in custody</div></div></a>
  <a class="stat-card stat-link accent-navy" href="{{ route('pdl.index', ['status' => 'active', 'cases' => 'both']) }}" style="text-decoration:none;color:inherit;"><div class="stat-icon">@include('partials.icon', ['name' => 'scale'])</div><div><div class="stat-label">Both Drug &amp; Non-drug</div><div class="stat-value">{{ $stats['both'] }}</div><div class="muted-cell">PDLs in custody</div></div></a>
  <a class="stat-card stat-link accent-amber" href="{{ route('pdl.index', ['status' => 'active', 'restricted' => 1]) }}" style="text-decoration:none;color:inherit;"><div class="stat-icon">@include('partials.icon', ['name' => 'calendar-x'])</div><div><div class="stat-label">With Restrictions</div><div class="stat-value">{{ $stats['restricted'] }}</div><div class="muted-cell">visits blocked today</div></div></a>
  <a class="stat-card stat-link accent-navy" href="{{ route('pdl.index', ['status' => 'transferred']) }}" style="text-decoration:none;color:inherit;"><div class="stat-icon">@include('partials.icon', ['name' => 'transfer'])</div><div><div class="stat-label">Transferred</div><div class="stat-value">{{ $stats['transferred'] }}</div></div></a>
  <a class="stat-card stat-link accent-amber" href="{{ route('pdl.index', ['status' => 'deceased']) }}" style="text-decoration:none;color:inherit;"><div class="stat-icon">@include('partials.icon', ['name' => 'user-minus'])</div><div><div class="stat-label">Deceased</div><div class="stat-value">{{ $stats['deceased'] }}</div></div></a>
  <a class="stat-card stat-link accent-gray" href="{{ route('pdl.index', ['status' => 'released']) }}" style="text-decoration:none;color:inherit;"><div class="stat-icon">@include('partials.icon', ['name' => 'unlock'])</div><div><div class="stat-label">Released YTD</div><div class="stat-value">{{ $stats['released_ytd'] }}</div><div class="muted-cell">since {{ now()->startOfYear()->format('M j, Y') }}</div></div></a>
</div>
<p class="muted-cell" style="margin:-6px 0 18px;">
  Cases on file for PDLs in custody: <strong>{{ $stats['drug_cases'] }}</strong> drug-related ·
  <strong>{{ $stats['all_cases'] - $stats['drug_cases'] }}</strong> non-drug.
  A PDL with both kinds of cases is counted once, under "Both"; for housing and visiting days they are classified drug-related.
</p>

@if ($restricted)
  <div class="elig-note is-warn" style="margin:0 0 14px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <span>Showing only PDLs with a restriction in effect today. Visits with them are blocked until it ends or is lifted.</span>
    <a href="{{ route('pdl.index') }}" style="color:inherit;font-weight:700;">Show all PDLs</a>
  </div>
@endif

@if ($cases)
  <div class="elig-note is-warn" style="margin:0 0 14px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;background:var(--blue-bg);color:var(--blue);">
    <span>Showing only PDLs in custody with {{ lcfirst(\App\Models\Pdl::CASE_GROUPS[$cases]) }}.</span>
    <a href="{{ route('pdl.index') }}" style="color:inherit;font-weight:700;">Show all PDLs</a>
  </div>
@endif

@if ($hearing)
  <div class="elig-note is-warn" style="margin:0 0 14px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;background:var(--blue-bg);color:var(--blue);">
    <span>Showing only PDLs with a court hearing in the next 7 days.</span>
    <a href="{{ route('pdl.index') }}" style="color:inherit;font-weight:700;">Show all PDLs</a>
  </div>
@endif

<form class="filters" method="GET" action="{{ route('pdl.index') }}">
  @if ($hearing)<input type="hidden" name="hearing" value="week">@endif
  @if ($cases)<input type="hidden" name="cases" value="{{ $cases }}">@endif
  @if ($restricted)<input type="hidden" name="restricted" value="1">@endif
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

  @if ($query || $status || $cellBlock || $offense || $visits || $hearing || $cases || $restricted)
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
        <th>PDL Number</th><th>Last Name</th><th>First Name</th><th>Middle Name</th><th>Gender</th><th>Offense / Classification</th>
        <th>Custody Status</th><th>Cell/Block</th><th>Admission Date</th><th>Last Visit</th><th>Visiting</th>
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
        @php
          // Older PDLs have only a full name on file (never split, so a
          // name is not guessed wrong): it spans the three name columns.
          $nameSplit = $pdl->last_name !== null || $pdl->first_name !== null;
        @endphp
        <td @unless ($nameSplit) colspan="3" @endunless>
          <div class="name-cell">
            @include('pdl.partials.avatar', ['pdl' => $pdl])
            <div>
              <a class="full-name" href="{{ route('pdl.show', $pdl->pdl_id) }}">
                {{ $nameSplit ? trim($pdl->last_name . ' ' . $pdl->suffix) : $pdl->full_name }}
                @if ($pdl->alias) <span class="muted-cell">"{{ $pdl->alias }}"</span> @endif
              </a>
              @unless ($nameSplit)
                <div class="muted-cell" style="font-size:12px;">Name not split yet. Use Edit Profile to enter last, first and middle name.</div>
              @endunless
              @if ($next = $nextHearings[$pdl->pdl_id] ?? null)
                <a class="hearing-tag" href="{{ route('pdl.show', $pdl->pdl_id) }}#legal-records" title="Court hearing{{ $next->notes ? ': ' . $next->notes : '' }}">
                  ⚖ Hearing {{ $next->hearing_at->format('D, M j') }} · {{ $next->hearing_at->format('H:i') }}
                </a>
              @endif
            </div>
          </div>
        </td>
        @if ($nameSplit)
          <td>{{ $pdl->first_name ?: '—' }}</td>
          <td class="muted-cell">{{ $pdl->middle_name ?: '—' }}</td>
        @endif
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
               href="{{ route('visitation-tracking.show', $pdl->pdl_id) }}" title="{{ $lastVisit->format('M j, Y H:i') }}">
              {{ $lastVisit->format('M d, Y') }}<br><span style="font-size:11px;">{{ $lastVisit->diffForHumans() }}</span>
            </a>
          @elseif ($pdl->custody_status === 'active')
            <span class="visit-never">Never visited</span>
          @else
            <span class="muted-cell">—</span>
          @endif
        </td>
        <td>
          @php $avail = \App\Services\PdlVisitingAvailability::for($pdl, $visitingRules); @endphp
          <span class="badge {{ \App\Services\PdlVisitingAvailability::badgeClass($avail['state']) }}" title="{{ $avail['weekly'] ?: 'No schedule set' }} ({{ $avail['source'] }})"><span class="dot"></span>{{ $avail['label'] }}</span>
          @if ($avail['state'] === 'restricted' && ($activeRestriction = $pdl->restrictions->first(fn ($r) => $r->coversDate(today()))))
            <div class="muted-cell" style="font-size:12px;margin-top:4px;" title="{{ $activeRestriction->reason }}">
              {{ $activeRestriction->end_date ? 'Until ' . $activeRestriction->end_date->format('M j, Y') : 'Until lifted' }}
              · <a href="{{ route('pdl.show', $pdl->pdl_id) }}#restrictions" style="color:inherit;">{{ \Illuminate\Support\Str::limit($activeRestriction->reason, 40) }}</a>
            </div>
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
@endsection
