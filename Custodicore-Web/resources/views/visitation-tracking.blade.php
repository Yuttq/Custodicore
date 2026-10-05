@extends('layouts.app')
@section('title', 'Visitation Tracking — CustodiCore')

@section('content')
<div class="topbar">
  <h1>Visitation Tracking</h1>
</div>

@if ($noVisitCount > 0)
<div class="panel" style="display:flex;align-items:center;justify-content:space-between;gap:16px;background:var(--amber-bg);border-color:#f2dcae;margin-bottom:18px;">
  <div>
    <div style="font-weight:700;font-size:14px;">PDLs with No Visit Record: {{ $noVisitCount }}</div>
    <div class="muted-cell" style="margin-top:4px;">These PDLs have had no authorized visits since intake and may need follow-up.</div>
  </div>
</div>
@endif

<div class="panel-table">
  @if ($pdls->isEmpty())
    <p class="empty-note" style="padding:20px;">No detained PDLs to show.</p>
  @else
    <table>
      <thead>
        <tr><th>PDL</th><th>PDL Number</th><th>Custody Status</th><th>Visitation Status</th><th>Actions</th></tr>
      </thead>
      <tbody>
        @foreach ($pdls as $pdl)
        <tr>
          <td>
            <div class="name-cell">
              <div class="thumb">{{ $pdl->initials() }}</div>
              <span class="full-name" style="cursor:default;">{{ $pdl->full_name }}</span>
            </div>
          </td>
          <td class="pdl-id">{{ $pdl->pdl_number }}</td>
          <td><span class="badge {{ $pdl->custody_status }}"><span class="dot"></span>{{ strtoupper($pdl->custody_status) }}</span></td>
          <td>
            @switch($pdl->visitFlag)
              @case('no_visit')
                <span class="flag">No Visit<br>Record</span>
                @break
              @case('long_time_no_visit')
                <span class="flag">Long Time<br>No Visit</span>
                @break
              @default
                <span class="muted-cell">Recent Visit</span>
            @endswitch
          </td>
          <td>
            <a class="row-action" href="{{ route('visitation-tracking.show', $pdl->pdl_id) }}">View History</a>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>

    <div class="table-footer">
      <span>Showing {{ $pdls->firstItem() }}–{{ $pdls->lastItem() }} of {{ $pdls->total() }} records</span>
      <div class="pagination">{{ $pdls->links() }}</div>
    </div>
  @endif
</div>
@endsection
