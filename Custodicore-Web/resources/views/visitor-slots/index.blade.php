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
  {{-- Each card leads somewhere: the list, who booked, and the sessions still open. --}}
  <a class="stat-card stat-link" href="#sessions" title="Go to the sessions list">
    <div class="stat-label">Sessions Listed</div><div class="stat-value">{{ $stats['sessions'] }}</div>
    <div class="muted-cell">view list ↓</div>
  </a>
  <a class="stat-card stat-link" href="#sessions" title="Go to each session's capacity">
    <div class="stat-label">Total Capacity</div><div class="stat-value">{{ $stats['capacity'] }}</div>
    <div class="muted-cell">per session ↓</div>
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

{{-- Sessions visitors can't book yet: weekly sessions in the next 7 days with nothing published. --}}
@if ($unpublished->isNotEmpty())
  <div class="panel unpublished-panel" id="unpublished">
    <div class="panel-header">
      <h2>Not published yet <span class="count-chip">{{ $unpublished->count() }}</span></h2>
      <span class="muted-cell">Visitors can only book published sessions. Publish these to open them for booking.</span>
    </div>
    <div class="table-scroll">
    <table>
      <thead><tr><th>Date</th><th>Session</th><th>Visiting Group</th><th>Default Capacity</th><th></th></tr></thead>
      <tbody>
        @foreach ($unpublished as $item)
          @php
            $rule = $item['rule'];
            $date = $item['date'];
            $session = \App\Services\VisitSlotService::sessionOf($rule->time_slot_start);
            $groupKey = $rule->groupKey();
            $groupName = $groups[$groupKey] ?? $groupKey;
          @endphp
          <tr>
            <td><strong>{{ $date->format('D, M j, Y') }}</strong></td>
            <td>{{ ucfirst($session) }} <span class="muted-cell">{{ $rule->hoursLabel() }}</span></td>
            <td>{{ str_starts_with($groupKey, 'dorm:') ? 'Dorm: ' . $groupName : $groupName }}</td>
            <td>{{ $rule->max_capacity }}</td>
            <td style="text-align:right;">
              <form method="POST" action="{{ route('visitor-slots.store') }}" style="margin:0;"
                    data-confirm="Publish the {{ $session }} session on {{ $date->format('M j, Y') }} for {{ $groupName }} with {{ $rule->max_capacity }} slots? Visitors can book it right away."
                    data-confirm-title="Publish session" data-confirm-ok="Publish">
                @csrf
                <input type="hidden" name="schedule_date" value="{{ $date->toDateString() }}">
                <input type="hidden" name="pdl_classification" value="{{ $groupKey }}">
                <input type="hidden" name="session" value="{{ $session }}">
                <input type="hidden" name="max_capacity" value="{{ $rule->max_capacity }}">
                <input type="hidden" name="is_open" value="1">
                <button type="submit" class="btn btn-blue" style="padding:6px 14px;">Publish</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
    </div>
  </div>
@endif

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
        <div class="row cols-2">
          <div class="field-m" style="position:relative;">
            <label for="slot_date">Visiting Date</label>
            {{-- The calendar below fills this in; it stays a required field so the form can't be sent without a date. --}}
            <input id="slot_date" class="cal-value" type="text" name="schedule_date" value="{{ old('schedule_date') }}" required tabindex="-1" autocomplete="off">
            <div class="mini-cal" id="slotCalendar"
                 data-today="{{ $today }}"
                 data-max="{{ \Carbon\Carbon::parse($today)->addDays((int) config('visitation.slot_management_horizon_days', 365))->toDateString() }}"></div>
            <p class="field-hint" id="slot_date_hint"></p>
          </div>
          <div class="field-m">
            <label for="slot_session">Session</label>
            <select id="slot_session" name="session" required>
              <option value="morning" @selected(old('session') === 'morning')>Morning</option>
              <option value="afternoon" @selected(old('session') === 'afternoon')>Afternoon</option>
              <option value="{{ \App\Services\VisitSlotService::WHOLE_DAY }}" @selected(old('session') === \App\Services\VisitSlotService::WHOLE_DAY)>Whole day (morning + afternoon)</option>
            </select>
            <p class="field-hint">Whole day creates both sessions for the date, each with the maximum below.</p>
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

@php
  // Each visiting group's days and sessions from the weekly sessions still in
  // effect (Thursday to Sunday only), so the form can block the other days.
  $groupDays = $rules
    ->filter(fn ($r) => ! $r->effective_to || $r->effective_to->toDateString() >= $today)
    ->filter(fn ($r) => in_array($r->day_of_week, \App\Services\VisitSlotService::VISITING_DAYS, true))
    ->groupBy(fn ($r) => $r->groupKey())
    ->map(fn ($groupRules) => $groupRules->groupBy('day_of_week')
      ->map(fn ($dayRules) => $dayRules->map(fn ($r) => \App\Services\VisitSlotService::sessionOf($r->time_slot_start))->unique()->values()));
@endphp
<script type="application/json" id="slotGroupDays">@json($groupDays)</script>
<script>
  function openSlotModal() {
    document.getElementById('slotModal').classList.add('open');
    document.getElementById('slot_classification').focus();
  }

  // Calendar for the visiting date: only the chosen group's visiting days
  // (drug-related, non-drug-related or a dorm's own, all within Thursday to
  // Sunday), from today to the booking horizon, are highlighted and can be
  // picked; every other day is greyed out. The Session list then offers only
  // that day's sessions. The server checks the same rules.
  (function () {
    var group = document.getElementById('slot_classification');
    var input = document.getElementById('slot_date');
    var session = document.getElementById('slot_session');
    var hint = document.getElementById('slot_date_hint');
    var cal = document.getElementById('slotCalendar');
    var groupDays = JSON.parse(document.getElementById('slotGroupDays').textContent || '{}');
    var keys = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
    var names = { thu: 'Thursday', fri: 'Friday', sat: 'Saturday', sun: 'Sunday' };
    var order = ['thu', 'fri', 'sat', 'sun'];
    var monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    var today = cal.dataset.today, max = cal.dataset.max;
    var view = (input.value || today).slice(0, 7).split('-').map(Number); // [year, month 1-12]

    function iso(y, m, d) { return y + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0'); }
    function dayKeyOf(value) { return keys[new Date(value + 'T00:00:00').getDay()]; }
    function list(items) { return items.length < 2 ? items.join('') : items.slice(0, -1).join(', ') + ' and ' + items[items.length - 1]; }
    function groupName() { return group.options[group.selectedIndex].text; }
    function days() { return groupDays[group.value] || {}; }

    // Why a date can't be picked, or '' when it can.
    function blockedReason(value) {
      if (value < today) return 'Past date';
      if (value > max) return 'Too far ahead';
      var key = dayKeyOf(value);
      if (order.indexOf(key) === -1) return 'Monday to Wednesday are not visiting days';
      if (!days()[key]) return 'Not a visiting day for ' + groupName();
      return '';
    }

    function render() {
      var y = view[0], m = view[1];
      var first = new Date(y, m - 1, 1).getDay();
      var count = new Date(y, m, 0).getDate();
      var html = '<div class="mini-cal-head">'
        + '<button type="button" class="mini-cal-nav" data-step="-1" aria-label="Previous month"' + (iso(y, m, 1) <= today.slice(0, 8) + '01' ? ' disabled' : '') + '>‹</button>'
        + '<strong>' + monthNames[m - 1] + ' ' + y + '</strong>'
        + '<button type="button" class="mini-cal-nav" data-step="1" aria-label="Next month"' + (iso(y, m, count) >= max ? ' disabled' : '') + '>›</button>'
        + '</div><div class="mini-cal-grid">';
      ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'].forEach(function (d) { html += '<span class="mini-cal-dow">' + d + '</span>'; });
      for (var i = 0; i < first; i++) html += '<span></span>';
      for (var d = 1; d <= count; d++) {
        var value = iso(y, m, d), reason = blockedReason(value);
        var cls = 'mini-cal-day' + (reason ? ' is-blocked' : ' is-allowed') + (value === input.value ? ' is-selected' : '') + (value === today ? ' is-today' : '');
        html += '<button type="button" class="' + cls + '" data-date="' + value + '"' + (reason ? ' disabled title="' + reason + '"' : ' title="Pick ' + value + '"') + '>' + d + '</button>';
      }
      cal.innerHTML = html + '</div>';
    }

    function update() {
      // A date that no longer fits the group (e.g. after switching group) is cleared.
      if (input.value && blockedReason(input.value)) input.value = '';
      var allowed = order.filter(function (d) { return days()[d]; }).map(function (d) { return names[d]; });

      input.setCustomValidity(input.value ? '' : 'Pick a highlighted day on the calendar.');
      hint.style.color = allowed.length ? '' : 'var(--red-text)';
      hint.textContent = !allowed.length
        ? 'No visiting days are set up for ' + groupName() + '.'
        : (input.value ? 'Selected: ' + new Date(input.value + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' }) + '. ' : '')
          + 'Visiting days for ' + groupName() + ': ' + list(allowed) + '.';

      // Sessions held that day; whole day needs both.
      var held = input.value ? days()[dayKeyOf(input.value)] : ['morning', 'afternoon'];
      Array.prototype.forEach.call(session.options, function (opt) {
        opt.disabled = opt.value === 'whole_day'
          ? !(held.indexOf('morning') !== -1 && held.indexOf('afternoon') !== -1)
          : held.indexOf(opt.value) === -1;
      });
      if (session.selectedOptions[0] && session.selectedOptions[0].disabled) {
        var firstOk = Array.prototype.find.call(session.options, function (o) { return !o.disabled; });
        if (firstOk) session.value = firstOk.value;
      }
      render();
    }

    cal.addEventListener('click', function (e) {
      var nav = e.target.closest('[data-step]');
      if (nav && !nav.disabled) {
        view[1] += +nav.dataset.step;
        if (view[1] < 1) { view[1] = 12; view[0]--; }
        if (view[1] > 12) { view[1] = 1; view[0]++; }
        render();
        return;
      }
      var day = e.target.closest('[data-date]');
      if (day && !day.disabled) {
        input.value = day.dataset.date;
        update();
      }
    });
    group.addEventListener('change', update);
    update();
  })();
  function closeSlotModal() {
    document.getElementById('slotModal').classList.remove('open');
  }
</script>
@endsection
