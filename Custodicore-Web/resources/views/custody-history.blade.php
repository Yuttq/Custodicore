@extends('layouts.app')
@section('title', 'Custody History — CustodiCore')

@section('content')
<div class="topbar">
  <h1>Custody History</h1>
</div>

{{--
  NOTE: audit_logs.description is free text, not structured old/new value
  columns. The "X → Y" formatting you see below only works because
  PdlController::update() consistently writes descriptions in that shape
  when it logs a change — if anyone else writes to audit_logs with
  record_type = 'pdl_custody_status' in a different format, this display
  will just show whatever text they wrote instead of parsing it further.
--}}

<div class="panel-table">
  @if ($entries->isEmpty())
    <p class="empty-note" style="padding:20px;">No custody status changes recorded yet.</p>
  @else
    <table>
      <thead>
        <tr><th>Date &amp; Time</th><th>PDL</th><th>Change</th><th>Updated By</th></tr>
      </thead>
      <tbody>
        @foreach ($entries as $entry)
        @php $pdl = $pdls->get($entry->record_id); @endphp
        <tr>
          <td class="muted-cell">{{ $entry->created_at->format('M d, Y, H:i') }}</td>
          <td>
            @if ($pdl)
              <a href="{{ route('pdl.show', $pdl->pdl_id) }}" style="font-weight:700;color:var(--text);text-decoration:none;">
                {{ $pdl->full_name }}
              </a>
              <div class="pdl-id">{{ $pdl->pdl_number }}</div>
            @else
              <span class="muted-cell">PDL #{{ $entry->record_id }} (not found)</span>
            @endif
          </td>
          <td>{{ $entry->description }}</td>
          <td class="muted-cell">
            {{-- CONFIRMED: accounts has no name column itself — displayName()
                 resolves it through staff_profiles (or visitor_profiles),
                 per the actual migrations. --}}
            {{ $entry->account?->displayName() ?? 'Unknown officer' }}
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>

    <div class="table-footer">
      <span>Showing {{ $entries->firstItem() }}–{{ $entries->lastItem() }} of {{ $entries->total() }} historical records</span>
      <div class="pagination">{{ $entries->links() }}</div>
    </div>
  @endif
</div>

<div class="stat-strip" style="grid-template-columns:repeat(3,1fr);max-width:640px;">
  <div class="stat-card accent-blue"><div><div class="stat-label">Total Records</div><div class="stat-value">{{ $stats['total_records'] }}</div></div></div>
  <div class="stat-card accent-amber"><div><div class="stat-label">Recent Transferred</div><div class="stat-value">{{ $stats['recent_transferred'] }}</div></div></div>
  <div class="stat-card" style="border-left:3px solid var(--green);"><div><div class="stat-label">Total Released (YTD)</div><div class="stat-value">{{ $stats['total_released'] }}</div></div></div>
</div>

<div class="integrity-banner" style="margin-top:18px;">
  <span class="ic">🛡</span>
  <div class="txt">
    <strong>System Integrity:</strong> Custody history entries are written automatically whenever a PDL's
    custody status or cell/block assignment changes. This page is read-only — corrections should go
    through a new status update on the PDL's profile, not an edit to history itself.
  </div>
</div>
@endsection
