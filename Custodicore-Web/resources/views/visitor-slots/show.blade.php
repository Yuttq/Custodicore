@extends('layouts.app')
@section('title', 'Visiting Session — CustodiCore')

@section('content')
@php
  $statusBadge = ['open' => ['active', 'Open'], 'full' => ['rejected', 'Full'], 'closed' => ['released', 'Closed']];
  [$badgeClass, $badgeText] = $statusBadge[$schedule->status] ?? ['released', ucfirst($schedule->status)];
  $session = \App\Services\VisitSlotService::sessionOf($schedule->time_slot_start);
  $group = $schedule->rule?->pdl_classification === 'drug_related' ? 'Drug-related' : 'Non-drug-related';
  $capacityStatuses = \App\Services\VisitAssignmentService::CAPACITY_STATUSES;
@endphp

<div class="topbar">
  <div>
    <a class="row-action" href="{{ route('visitor-slots.index') }}">← Manage Visitor Slots</a>
    <h1 style="margin-top:6px;">
      {{ $schedule->schedule_date->format('l, M j, Y') }} · {{ ucfirst($session) }}
      ({{ substr($schedule->time_slot_start, 0, 5) }}–{{ substr($schedule->time_slot_end, 0, 5) }})
    </h1>
    <div class="muted-cell">{{ $group }} PDLs</div>
  </div>
  <div class="right"><span class="badge {{ $badgeClass }}"><span class="dot"></span>{{ $badgeText }}</span></div>
</div>

<div class="stat-strip" style="margin-bottom:20px;">
  <div class="stat-card"><div class="stat-label">Total Capacity</div><div class="stat-value">{{ $summary['capacity'] }}</div></div>
  <div class="stat-card"><div class="stat-label">Booked Slots</div><div class="stat-value">{{ $summary['booked'] }}</div></div>
  <div class="stat-card"><div class="stat-label">Remaining Slots</div><div class="stat-value">{{ $summary['remaining'] }}</div></div>
  <div class="stat-card"><div class="stat-label">New Bookings</div><div class="stat-value" style="font-size:16px;">{{ $summary['accepting'] ? 'Accepted' : 'Not accepted' }}</div></div>
</div>

@if ($summary['held'] > $summary['booked'])
  <p class="muted-cell" style="margin-bottom:16px;">
    The seat counter for this session holds {{ $summary['held'] }} seats but only {{ $summary['booked'] }} bookings exist
    (for example, sample data). Remaining slots are worked out from the counter until this session is saved, which resets it to the real bookings.
  </p>
@endif

@if ($editable)
<div class="panel-table" style="padding:18px 20px;">
  <form method="POST" action="{{ route('visitor-slots.update', $schedule->schedule_id) }}"
        data-confirm="Save the capacity and booking status of this session?" data-confirm-title="Save session" data-confirm-ok="Save"
        style="display:flex;gap:20px;align-items:flex-end;flex-wrap:wrap;">
    @csrf
    @method('PUT')
    <div class="field-m" style="min-width:180px;">
      <label for="max_capacity">Maximum Bookings</label>
      <input id="max_capacity" type="number" name="max_capacity"
             min="{{ max(\App\Services\VisitSlotService::MIN_CAPACITY, $summary['booked']) }}" max="{{ \App\Services\VisitSlotService::MAX_CAPACITY }}"
             value="{{ old('max_capacity', $summary['capacity']) }}" required>
    </div>
    <label style="display:flex;align-items:center;gap:8px;font-weight:500;font-size:13px;padding-bottom:10px;">
      <input type="hidden" name="is_open" value="0">
      <input type="checkbox" name="is_open" value="1" style="width:auto;" @checked(old('is_open', $schedule->status === 'closed' ? '0' : '1') === '1')>
      Open to new bookings
    </label>
    <button type="submit" class="btn btn-blue">Save Changes</button>
  </form>
  <p class="muted-cell" style="margin:12px 0 0 0;">
    Capacity can't go below the {{ $summary['booked'] }} active {{ $summary['booked'] === 1 ? 'booking' : 'bookings' }}.
    Closing keeps existing bookings and stops new ones.
  </p>
</div>
@else
  <p class="muted-cell" style="margin-bottom:16px;">This session has already ended, so it can no longer be changed.</p>
@endif

<div class="topbar" style="margin-top:12px;"><h1 style="font-size:16px;">Bookings ({{ $bookings->count() }})</h1></div>

<div class="panel-table">
  @if ($bookings->isEmpty())
    <p class="empty-note" style="padding:24px;">No visits are booked for this session yet.</p>
  @else
  <table>
    <thead><tr><th>Visit #</th><th>Visitor</th><th>PDL</th><th>Visit Covers</th><th>Status</th><th>Holds a Seat</th></tr></thead>
    <tbody>
      @foreach ($bookings as $visit)
        @php
          $holds = in_array($visit->status, $capacityStatuses, true);
          $sessionCount = $visit->sessionSchedules->count();
        @endphp
        <tr>
          <td>#{{ $visit->visit_request_id }}</td>
          <td>
            @if ($visit->visitor)
              <a class="row-action" href="{{ route('visitor.show', $visit->visitor->visitor_id) }}">{{ $visit->visitor->full_name }}</a>
            @else
              <span class="muted-cell">—</span>
            @endif
          </td>
          <td>{{ $visit->pdl?->full_name ?? '—' }}</td>
          <td class="muted-cell">{{ $sessionCount > 1 ? 'Whole day' : 'This session only' }}</td>
          <td>{{ $statusLabels[$visit->status] ?? ucfirst(str_replace('_', ' ', $visit->status)) }}</td>
          <td>
            @if ($holds)
              <span class="badge active"><span class="dot"></span>Yes</span>
            @else
              <span class="badge released"><span class="dot"></span>Released</span>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>
  @endif
</div>
@endsection
