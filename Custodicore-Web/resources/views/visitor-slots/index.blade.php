@extends('layouts.app')
@section('title', 'Manage Visitor Slots — CustodiCore')

@section('content')
@php
  $statusBadge = ['open' => ['active', 'Open'], 'full' => ['rejected', 'Full'], 'closed' => ['released', 'Closed']];
  // Rule group: a PDL classification or a dorm with its own visiting schedule.
  $groupLabel = fn ($rule) => $rule ? ($groups[$rule->groupKey()] ?? '—') : '—';
@endphp

<div class="topbar">
  <h1>Manage Visitor Slots</h1>
  <div class="right">
    <button type="button" class="btn btn-primary" onclick="openSlotModal()">Create Session</button>
  </div>
</div>

<div class="stat-strip" style="margin-bottom:20px;">
  {{-- Each card leads somewhere: the list, the default capacities, who booked, and the sessions still open. --}}
  <a class="stat-card stat-link" href="#sessions" title="Go to the sessions list">
    <div class="stat-label">Sessions Listed</div><div class="stat-value">{{ $stats['sessions'] }}</div>
    <div class="muted-cell">view list ↓</div>
  </a>
  <a class="stat-card stat-link" href="#weekly-defaults" title="Go to the default capacity per weekly session">
    <div class="stat-label">Total Capacity</div><div class="stat-value">{{ $stats['capacity'] }}</div>
    <div class="muted-cell">default capacities ↓</div>
  </a>
  <a class="stat-card stat-link" href="{{ route('upcoming-visits.index', ['range' => 'month']) }}" title="See who booked in Upcoming Visits">
    <div class="stat-label">Booked Slots</div><div class="stat-value">{{ $stats['booked'] }}</div>
    <div class="muted-cell">who's coming →</div>
  </a>
  <a class="stat-card stat-link" href="{{ route('visitor-slots.index', array_filter([...$filters, 'status' => 'open'])) }}#sessions" title="Show only open sessions">
    <div class="stat-label">Remaining Slots</div><div class="stat-value">{{ $stats['remaining'] }}</div>
    <div class="muted-cell">open sessions →</div>
  </a>
</div>

<form class="filters filters-5" method="GET" action="{{ route('visitor-slots.index') }}" id="sessions">
  <div class="field">
    <label for="from">From</label>
    <div class="input"><input id="from" type="date" name="from" value="{{ $filters['from'] }}"></div>
  </div>
  <div class="field">
    <label for="to">To</label>
    <div class="input"><input id="to" type="date" name="to" value="{{ $filters['to'] }}"></div>
  </div>
  <div class="field">
    <label for="classification">Visiting Group</label>
    <select id="classification" name="classification">
      <option value="">All</option>
      @foreach ($groups as $key => $label)
        <option value="{{ $key }}" @selected($filters['classification'] === $key)>{{ str_starts_with($key, 'dorm:') ? 'Dorm: ' . $label : $label }}</option>
      @endforeach
    </select>
  </div>
  <div class="field">
    <label for="status">Status</label>
    <select id="status" name="status">
      <option value="">All</option>
      <option value="open" @selected($filters['status'] === 'open')>Open</option>
      <option value="full" @selected($filters['status'] === 'full')>Full</option>
      <option value="closed" @selected($filters['status'] === 'closed')>Closed</option>
    </select>
  </div>
  <div class="field"><button type="submit" class="btn btn-blue">Filter</button></div>
</form>

<div class="panel-table">
  @if ($schedules->isEmpty())
    <p class="empty-note" style="padding:24px;">No visiting sessions match these filters. Create one, or widen the dates.</p>
  @else
  <div class="scroll-box">
  <table>
    <thead>
      <tr><th>Date</th><th>Session</th><th>Visiting Group</th><th>Booked / Capacity</th><th>Remaining</th><th>Status</th><th></th></tr>
    </thead>
    <tbody>
      @foreach ($schedules as $schedule)
        @php
          $s = $summaries[$schedule->schedule_id];
          $pct = min(100, round($s['held'] / max(1, $s['capacity']) * 100));
          [$badgeClass, $badgeText] = $statusBadge[$schedule->status] ?? ['released', ucfirst($schedule->status)];
          $canEdit = $editable[$schedule->schedule_id];
        @endphp
        <tr>
          <td><strong>{{ $schedule->schedule_date->format('D, M j, Y') }}</strong></td>
          <td>
            {{ ucfirst(\App\Services\VisitSlotService::sessionOf($schedule->time_slot_start)) }}
            <span class="muted-cell">{{ substr($schedule->time_slot_start, 0, 5) }}–{{ substr($schedule->time_slot_end, 0, 5) }}</span>
          </td>
          <td class="muted-cell">{{ $groupLabel($schedule->rule) }}</td>
          <td>
            <div class="occupancy-chip occupancy-inline {{ $s['remaining'] === 0 && $schedule->status !== 'closed' ? 'is-full' : ($pct >= 80 ? 'is-near' : '') }}" style="background:none;border:none;padding:0;">
              <div class="occupancy-bar"><span style="width:{{ $pct }}%"></span></div>
              <span class="occupancy-count">{{ $s['booked'] }}/{{ $s['capacity'] }}</span>
            </div>
          </td>
          <td>{{ $s['remaining'] }}</td>
          <td><span class="badge {{ $badgeClass }}"><span class="dot"></span>{{ $badgeText }}</span></td>
          <td style="text-align:right;white-space:nowrap;">
            <a class="row-action" href="{{ route('visitor-slots.show', $schedule->schedule_id) }}" style="margin-right:14px;">{{ $canEdit ? 'Manage' : 'View' }}</a>
            @if ($canEdit)
              @php $closing = $schedule->status !== 'closed'; @endphp
              <form method="POST" action="{{ route('visitor-slots.status', $schedule->schedule_id) }}" style="display:inline;"
                    data-confirm="{{ $closing ? 'Close this session to new bookings? Existing bookings are kept.' : 'Reopen this session to new bookings?' }}"
                    data-confirm-title="{{ $closing ? 'Close session' : 'Reopen session' }}"
                    data-confirm-ok="{{ $closing ? 'Close' : 'Reopen' }}" @if ($closing) data-confirm-danger @endif>
                @csrf
                @method('PATCH')
                <input type="hidden" name="is_open" value="{{ $closing ? 0 : 1 }}">
                <button type="submit" class="row-action" style="{{ $closing ? 'color:var(--red-text);' : '' }}">{{ $closing ? 'Close' : 'Reopen' }}</button>
              </form>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  </div>
  @endif
</div>

<p class="muted-cell">Booked counts visits that hold a seat: awaiting review, awaiting visitor confirmation, confirmed, completed and no-show. Cancelled and declined visits free their seat. Closing a session stops new bookings but keeps existing ones.</p>

<div class="topbar" style="margin-top:28px;" id="weekly-defaults">
  <h1 style="font-size:16px;">Default Capacity per Weekly Session</h1>
</div>
<p class="muted-cell" style="margin-top:-12px;">Used for every date of a weekly session that has no session listed above yet — the visitor app shows it, and the first booking creates the session with it. Sessions that already exist keep their own capacity.</p>

<div class="panel-table">
  @if ($rules->isEmpty())
    <p class="empty-note" style="padding:24px;">No weekly visitation rules are configured.</p>
  @else
  <div class="scroll-box">
  <table>
    <thead><tr><th>Visiting Group</th><th>Day</th><th>Session</th><th>Effective</th><th style="width:260px;">Default Capacity</th></tr></thead>
    <tbody>
      @foreach ($rules as $rule)
        <tr>
          <td class="muted-cell">{{ $groupLabel($rule) }}</td>
          <td><strong>{{ ucfirst($rule->day_of_week) }}</strong></td>
          <td>
            {{ ucfirst(\App\Services\VisitSlotService::sessionOf($rule->time_slot_start)) }}
            <span class="muted-cell">{{ substr($rule->time_slot_start, 0, 5) }}–{{ substr($rule->time_slot_end, 0, 5) }}</span>
          </td>
          <td class="muted-cell">
            {{ $rule->effective_from?->format('M j, Y') ?? '—' }} – {{ $rule->effective_to?->format('M j, Y') ?? 'ongoing' }}
          </td>
          <td>
            <form method="POST" action="{{ route('visitor-slots.rules.update', $rule->rule_id) }}" style="display:flex;gap:8px;align-items:center;"
                  data-confirm="Change the default capacity of this weekly session? Existing sessions keep their own capacity."
                  data-confirm-title="Default capacity" data-confirm-ok="Save">
              @csrf
              @method('PUT')
              <input type="number" name="max_capacity" value="{{ $rule->max_capacity }}" min="{{ \App\Services\VisitSlotService::MIN_CAPACITY }}" max="{{ \App\Services\VisitSlotService::MAX_CAPACITY }}" required
                     aria-label="Default capacity" style="width:90px;padding:8px 10px;border:1px solid var(--border);border-radius:10px;font-size:13px;">
              <button type="submit" class="row-action">Save</button>
            </form>
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  </div>
  @endif
</div>

{{-- Create session modal --}}
<div class="modal-overlay" id="slotModal" onclick="if (event.target === this) closeSlotModal()">
  <div class="modal modal-narrow">
    <form method="POST" action="{{ route('visitor-slots.store') }}"
          data-confirm="Create this visiting session?" data-confirm-title="Create session" data-confirm-ok="Create">
      @csrf
      <div class="modal-header">
        <div>
          <h2>Create Visiting Session</h2>
          <div class="sub">The day must be a visiting day for the chosen group (a PDL classification, or a dorm with its own visiting hours). Times follow the weekly session.</div>
        </div>
        <button type="button" class="close" onclick="closeSlotModal()">✕</button>
      </div>
      <div class="modal-body">
        <div class="row cols-2">
          <div class="field-m">
            <label for="slot_date">Visiting Date</label>
            <input id="slot_date" type="date" name="schedule_date" min="{{ $today }}" value="{{ old('schedule_date') }}" required>
          </div>
          <div class="field-m">
            <label for="slot_session">Session</label>
            <select id="slot_session" name="session" required>
              <option value="morning" @selected(old('session') === 'morning')>Morning</option>
              <option value="afternoon" @selected(old('session') === 'afternoon')>Afternoon</option>
            </select>
          </div>
        </div>
        <div class="row cols-2">
          <div class="field-m">
            <label for="slot_classification">Visiting Group</label>
            <select id="slot_classification" name="pdl_classification" required>
              @foreach ($groups as $key => $label)
                <option value="{{ $key }}" @selected(old('pdl_classification') === $key)>{{ str_starts_with($key, 'dorm:') ? 'Dorm: ' . $label : $label }}</option>
              @endforeach
            </select>
          </div>
          <div class="field-m">
            <label for="slot_capacity">Maximum Bookings</label>
            <input id="slot_capacity" type="number" name="max_capacity" min="{{ \App\Services\VisitSlotService::MIN_CAPACITY }}" max="{{ \App\Services\VisitSlotService::MAX_CAPACITY }}" value="{{ old('max_capacity') }}" required>
          </div>
        </div>
        <label style="display:flex;align-items:center;gap:8px;font-weight:500;font-size:13px;">
          <input type="hidden" name="is_open" value="0">
          <input type="checkbox" name="is_open" value="1" style="width:auto;" @checked(old('is_open', '1') === '1')>
          Open to new bookings
        </label>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-neutral" onclick="closeSlotModal()">Cancel</button>
        <button type="submit" class="btn btn-blue">Create Session</button>
      </div>
    </form>
  </div>
</div>

<script>
  function openSlotModal() {
    document.getElementById('slotModal').classList.add('open');
    document.getElementById('slot_date').focus();
  }
  function closeSlotModal() {
    document.getElementById('slotModal').classList.remove('open');
  }
</script>
@endsection
