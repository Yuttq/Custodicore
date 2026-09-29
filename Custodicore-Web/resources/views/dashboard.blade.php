@extends('layouts.app')
@section('title', 'Dashboard — CustodiCore')

@section('content')
<div class="topbar">
  <h1>Dashboard</h1>
  <form class="search" action="{{ route('pdl.index') }}" method="GET" style="flex:1;">
    <button type="submit" style="border:none;background:none;padding:0;color:inherit;font:inherit;cursor:pointer;">🔍</button>
    <input type="text" name="q" placeholder="Search PDL Records...">
  </form>
  <div class="right">
    <span class="bell">🔔</span>
    <span>{{ now()->format('l, j F Y') }}</span>
  </div>
</div>

<section class="stats">
  <div class="stat-card accent-blue">
    <div class="stat-top"><div class="icon-badge">👥</div></div>
    <div class="stat-label">Total PDLs</div>
    <div class="stat-value">{{ $stats['total_pdls'] }}</div>
  </div>
  <div class="stat-card accent-blue">
    <div class="stat-top"><div class="icon-badge">🔒</div></div>
    <div class="stat-label">Active Custody</div>
    <div class="stat-value">{{ $stats['active_custody'] }}</div>
  </div>
  <div class="stat-card">
    <div class="stat-top"><div class="icon-badge">⇄</div></div>
    <div class="stat-label">Released/Transferred</div>
    <div class="stat-value">{{ $stats['released_transferred'] }}</div>
  </div>
  <div class="stat-card accent-amber">
    <div class="stat-top"><div class="icon-badge">🚫</div>@if($stats['no_visit_record'] > 0)<span class="attention-badge">ATTENTION</span>@endif</div>
    <div class="stat-label">No Visit Record</div>
    <div class="stat-value">{{ $stats['no_visit_record'] }}</div>
  </div>
</section>

<section class="content">
  <div>
    <div class="panel" style="margin-bottom:20px;">
      <div class="panel-header"><h2>Operational Controls</h2></div>
      <div class="controls">
        <a class="btn btn-primary" href="{{ route('pdl.create') }}">+ Register New PDL</a>
        <a class="btn btn-outline" href="{{ route('pdl.index') }}">Update Custody Status</a>
      </div>

      <div class="panel-header">
        <h2>Recent Records Activity</h2>
        <a href="{{ route('pdl.index') }}" class="link">View PDL Management</a>
      </div>

      {{--
        NOTE: this is "PDLs most recently updated," not a true audit trail.
        Custody History (audit_logs-backed) now exists as its own page —
        consider swapping this panel to pull its most recent entries from
        there instead, for a real "what changed" view rather than "what was
        touched."
      --}}
      @if ($recentActivity->isEmpty())
        <p class="empty-note">No PDL records yet.</p>
      @else
        <table>
          <thead><tr><th>PDL</th><th>Custody Status</th><th>Last Updated</th></tr></thead>
          <tbody>
            @foreach ($recentActivity as $pdl)
            <tr>
              <td>
                <div class="pdl-cell">
                  <div class="pdl-avatar">{{ $pdl->initials() }}</div>
                  <div>
                    <a class="pdl-name" href="{{ route('pdl.show', $pdl->pdl_id) }}">{{ $pdl->full_name }}</a>
                    <div class="pdl-id">{{ $pdl->pdl_number }}</div>
                  </div>
                </div>
              </td>
              <td><span class="badge {{ strtolower($pdl->custody_status) }}"><span class="dot"></span>{{ strtoupper($pdl->custody_status) }}</span></td>
              <td class="muted-cell">{{ $pdl->updated_at->format('d M Y, H:i') }}</td>
            </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div>
  </div>

  <div class="panel alerts-panel">
    <div class="panel-header">
      <h2>Visitation Alerts</h2>
      <span class="badge">{{ $stats['no_visit_record'] }} No Visit</span>
    </div>

    @if ($visitationAlerts->isEmpty())
      <p class="empty-note">No visitation alerts right now.</p>
    @else
      @foreach ($visitationAlerts as $pdl)
      <div class="alert-item">
        <div class="alert-title">
          <span class="dot"></span> {{ $pdl->full_name }}
          <span class="pill no-visit">NO VISIT RECORD</span>
        </div>
        <div class="alert-desc">Admitted {{ $pdl->admission_date->format('M d, Y') }}. No visitation records established since intake.</div>
        <a href="{{ route('visitation-tracking.show', $pdl->pdl_id) }}" class="alert-link">View Full Profile →</a>
      </div>
      @endforeach
    @endif
  </div>
</section>
@endsection
