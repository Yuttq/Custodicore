@extends('layouts.app')
@section('title', 'Dashboard — CustodiCore')

@section('content')
@php
  $me = auth()->user();
  $firstName = $me?->staffProfile?->first_name ?: (preg_split('/\s+/', trim($me?->displayName() ?? 'Officer'))[0] ?? 'Officer');
@endphp
<div class="topbar topbar-home">
  <div>
    <span class="hero-date">{{ now()->format('l, j F Y') }}</span>
    <h1 id="greeting">Welcome back, {{ $firstName }}</h1>
    <p class="hero-sub">Here is what is happening with the PDL records today.</p>
  </div>
  <form class="search" action="{{ route('pdl.index') }}" method="GET" style="flex:1;max-width:380px;margin-left:auto;">
    <input type="text" name="q" placeholder="Search PDL Records...">
  </form>
</div>
<script>
  // Greeting by the visitor's own clock (the server may be in another timezone).
  (function () {
    var h = new Date().getHours();
    var g = h < 12 ? 'Good morning' : (h < 18 ? 'Good afternoon' : 'Good evening');
    var el = document.getElementById('greeting');
    if (el) { el.textContent = g + ', ' + @json($firstName); }
  })();
</script>

<section class="stats stats-5">
  <div class="stat-card accent-blue">
    <div class="stat-top"><div class="icon-badge">👥</div></div>
    <div class="stat-label">Total PDLs</div>
    <div class="stat-value">{{ $stats['total_pdls'] }}</div>
    <div class="muted-cell">in custody now</div>
  </div>
  <div class="stat-card">
    <div class="stat-top"><div class="icon-badge">⇄</div></div>
    <div class="stat-label">Transferred</div>
    <div class="stat-value">{{ $stats['transferred'] }}</div>
  </div>
  <div class="stat-card">
    <div class="stat-top"><div class="icon-badge">🔓</div></div>
    <div class="stat-label">Released</div>
    <div class="stat-value">{{ $stats['released'] }}</div>
  </div>
  <div class="stat-card accent-amber">
    <div class="stat-top"><div class="icon-badge">🚫</div>@if($stats['no_visit_record'] > 0)<span class="attention-badge">ATTENTION</span>@endif</div>
    <div class="stat-label">No Visit Record</div>
    <div class="stat-value">{{ $stats['no_visit_record'] }}</div>
  </div>
  <a class="stat-card accent-teal" href="{{ route('visitor.index') }}#visit-requests" style="text-decoration:none;color:inherit;">
    <div class="stat-top"><div class="icon-badge">📋</div>@if($stats['pending_visit_requests'] > 0)<span class="attention-badge">ATTENTION</span>@endif</div>
    <div class="stat-label">Visit Requests Awaiting Review</div>
    <div class="stat-value">{{ $stats['pending_visit_requests'] }}</div>
    <div class="muted-cell">open Visitor Management to review</div>
  </a>
</section>

<section class="content">
  <div>
    <div class="panel" style="margin-bottom:20px;">
      <div class="panel-header"><h2>Operational Controls</h2></div>
      <div class="controls">
        <a class="btn btn-primary" href="{{ route('pdl.create') }}">Register New PDL</a>
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
                  @include('pdl.partials.avatar', ['pdl' => $pdl, 'class' => 'pdl-avatar'])
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
        <a href="{{ route('visitation-tracking.show', $pdl->pdl_id) }}" class="alert-link">View Full Profile</a>
      </div>
      @endforeach
    @endif
  </div>
</section>
@endsection
