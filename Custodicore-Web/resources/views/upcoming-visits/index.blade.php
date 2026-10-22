@extends('layouts.app')
@section('title', 'Upcoming Visits — CustodiCore')

@section('content')
@php
  use App\Http\Controllers\UpcomingVisitsController as Upcoming;

  $statusBadge = [
    'confirmed' => ['active', 'Confirmed'],
    'pending_confirmation' => ['transferred', 'Visitor to confirm'],
    'assigned' => ['transferred', 'Waiting for review'],
    'completed' => ['detained', 'Completed'],
    'no_show' => ['released', 'No-show'],
  ];

  // Morning, afternoon or whole day, with the times.
  $sessionLabel = function ($visit) {
    $sessions = $visit->sessionSchedules->isNotEmpty() ? $visit->sessionSchedules : collect([$visit->schedule]);
    $first = $sessions->first();
    $last = $sessions->last();
    $kind = $sessions->count() > 1 ? 'Whole day' : ((string) $first->time_slot_start < '12:00' ? 'Morning' : 'Afternoon');

    return [$kind, substr((string) $first->time_slot_start, 0, 5) . '–' . substr((string) $last->time_slot_end, 0, 5)];
  };

  $link = fn (array $change) => route('upcoming-visits.index', array_filter(array_merge(['range' => $range, 'status' => $status], $change)));
@endphp

<div class="topbar">
  <h1>Upcoming Visits</h1>
</div>
<p class="muted-cell" style="margin:-8px 0 16px;">
  Who is coming to visit. Approved requests show here as <strong>Confirmed</strong>; on the day, the front desk checks the visitor in with their QR pass.
</p>

<section class="stats" style="margin-bottom:18px;">
  <a class="stat-card stat-link accent-teal" href="{{ $link(['range' => 'today', 'status' => '']) }}" style="text-decoration:none;color:inherit;">
    <div class="stat-label">Visits Today</div>
    <div class="stat-value">{{ $stats['today'] }}</div>
  </a>
  <a class="stat-card stat-link accent-blue" href="{{ $link(['range' => 'week', 'status' => 'confirmed']) }}" style="text-decoration:none;color:inherit;">
    <div class="stat-label">Confirmed, Next 7 Days</div>
    <div class="stat-value">{{ $stats['week'] }}</div>
  </a>
  <a class="stat-card stat-link accent-amber" href="{{ $link(['range' => 'month', 'status' => 'pending_confirmation']) }}" style="text-decoration:none;color:inherit;">
    <div class="stat-label">Waiting for Visitor to Confirm</div>
    <div class="stat-value">{{ $stats['awaiting_visitor'] }}</div>
    <div class="muted-cell">staff-assigned visits</div>
  </a>
  <a class="stat-card stat-link" href="{{ route('eligibility.index') }}" style="border-left:3px solid var(--amber);text-decoration:none;color:inherit;" title="Open the Review Queue">
    <div class="stat-label">Waiting for Review</div>
    <div class="stat-value">{{ $stats['awaiting_review'] }}</div>
    <div class="muted-cell">review requests →</div>
  </a>
</section>

<div class="elig-tabs" role="tablist" style="margin-bottom:10px;">
  @foreach (Upcoming::RANGES as $key => $label)
    <a href="{{ $link(['range' => $key]) }}" class="elig-tab {{ $range === $key ? 'is-active' : '' }}" role="tab">{{ $label }}</a>
  @endforeach
</div>
<div class="elig-tabs" role="tablist" style="margin-bottom:18px;">
  <a href="{{ $link(['status' => '']) }}" class="elig-tab {{ $status === '' ? 'is-active' : '' }}" role="tab">All statuses</a>
  @foreach (Upcoming::STATUSES as $key => $label)
    <a href="{{ $link(['status' => $key]) }}" class="elig-tab {{ $status === $key ? 'is-active' : '' }}" role="tab">{{ $label }}</a>
  @endforeach
</div>

<div class="scroll-box scroll-box-plain">
@forelse ($byDate as $date => $visits)
  @php $day = \Carbon\Carbon::parse($date); @endphp
  <div class="panel" style="margin-bottom:16px;">
    <div class="panel-header">
      <h2>{{ $date === $todayKey ? 'Today · ' : '' }}{{ $day->format('l, M j, Y') }}</h2>
      <span class="muted-cell">{{ $visits->count() }} {{ $visits->count() === 1 ? 'visit' : 'visits' }}</span>
    </div>
    <div class="table-scroll">
    <table>
      <thead>
        <tr><th>Session</th><th>Visitor</th><th>PDL</th><th>Status</th><th>Approved</th></tr>
      </thead>
      <tbody>
        @foreach ($visits as $visit)
          @php
            [$kind, $times] = $sessionLabel($visit);
            [$badgeClass, $badgeText] = $statusBadge[$visit->status] ?? ['released', ucfirst(str_replace('_', ' ', $visit->status))];
            $inside = $visit->checkins->contains('status', 'checked_in');
            $wasIn = ! $inside && $visit->checkins->isNotEmpty();
          @endphp
          <tr>
            <td>
              <strong>{{ $kind }}</strong>
              <div class="muted-cell" style="font-size:12px;">{{ $times }}</div>
            </td>
            <td>
              @if ($visit->visitor)
                <a class="full-name" href="{{ route('visitor.show', $visit->visitor_id) }}">{{ $visit->visitor->full_name }}</a>
              @else
                —
              @endif
              @if ($visit->relationship)
                <div class="muted-cell" style="font-size:12px;">{{ $visit->relationship->relationshipLabel() }}</div>
              @endif
            </td>
            <td>
              @if ($visit->pdl)
                <a class="full-name" href="{{ route('pdl.show', $visit->pdl_id) }}">{{ $visit->pdl->full_name }}</a>
                <div class="muted-cell" style="font-size:12px;">{{ $visit->pdl->pdl_number }}</div>
              @else
                —
              @endif
            </td>
            <td>
              <span class="badge {{ $badgeClass }}"><span class="dot"></span>{{ $badgeText }}</span>
              @if ($inside)
                <div style="font-size:12px;margin-top:4px;color:var(--green);font-weight:600;">Inside now</div>
              @elseif ($wasIn && $visit->status === 'confirmed')
                <div class="muted-cell" style="font-size:12px;margin-top:4px;">Out for the midday break</div>
              @endif
              @if ($visit->status === 'assigned')
                <div style="font-size:12px;margin-top:4px;"><a href="{{ route('eligibility.index') }}" style="color:var(--teal);font-weight:600;">Review →</a></div>
              @elseif ($visit->status === 'pending_confirmation' && $visit->confirmation_deadline)
                <div class="muted-cell" style="font-size:12px;margin-top:4px;">Confirm by {{ $visit->confirmation_deadline->format('M j, H:i') }}</div>
              @endif
            </td>
            <td class="muted-cell">{{ $visit->confirmed_at?->format('M j, Y H:i') ?? '—' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  </div>
@empty
  <div class="panel">
    <p class="empty-note">
      No visits {{ $range === 'today' ? 'today' : 'in the ' . strtolower(Upcoming::RANGES[$range]) }}{{ $status ? ' with status "' . Upcoming::STATUSES[$status] . '"' : '' }}.
    </p>
  </div>
@endforelse
</div>
@endsection
