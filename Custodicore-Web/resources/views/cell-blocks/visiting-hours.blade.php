@extends('layouts.app')
@section('title', $cellBlock->name . ' Visiting Hours — CustodiCore')

@php
  use App\Models\FacilityVisitationRule as R;
  $byDay = $rules->groupBy('day_of_week');
@endphp

@section('content')
<div class="topbar">
  <div>
    <a class="row-action" href="{{ route('cell-blocks.index') }}">← Back to Cell Blocks</a>
    <h1 style="margin:6px 0 0;">{{ $cellBlock->name }}: Visiting Hours</h1>
    <p class="muted-cell" style="margin:4px 0 0;">
      {{ $pdlCount }} {{ $pdlCount === 1 ? 'PDL' : 'PDLs' }} in custody here.
      @if ($rules->isEmpty())
        This dorm has no visiting hours yet, so its PDLs follow the classification days
        (drug-related Thu &amp; Sat, non-drug-related Fri &amp; Sun).
      @else
        Its PDLs can only be visited during these hours. All times are in 24-hour format.
      @endif
    </p>
  </div>
</div>

{{-- Weekly view --}}
<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Weekly Schedule</h2></div>
  <div class="week-grid">
    @foreach (R::DAY_LABELS as $key => $label)
      <div class="week-day {{ $byDay->has($key) ? 'has-hours' : '' }}">
        <div class="week-day-name">{{ $label }}</div>
        @forelse ($byDay->get($key, collect()) as $rule)
          <div class="week-slot">
            <div class="week-slot-time">{{ $rule->hoursLabel() }}</div>
            <div class="muted-cell">{{ $rule->max_capacity }} visitors / session</div>
            <form method="POST" action="{{ route('cell-blocks.visiting-hours.destroy', [$cellBlock->cell_block_id, $rule->rule_id]) }}"
                  data-confirm="Remove {{ $label }} {{ $rule->hoursLabel() }} from {{ $cellBlock->name }}'s visiting hours?"
                  data-confirm-title="Remove visiting hours" data-confirm-ok="Remove" data-confirm-danger>
              @csrf
              @method('DELETE')
              <button type="submit" class="row-action" style="color:var(--red-text);">Remove</button>
            </form>
          </div>
        @empty
          <div class="muted-cell week-none">No visiting</div>
        @endforelse
      </div>
    @endforeach
  </div>
</div>

{{-- Add hours --}}
<div class="panel">
  <div class="panel-header"><h2>Add Visiting Hours</h2></div>
  <form method="POST" action="{{ route('cell-blocks.visiting-hours.store', $cellBlock->cell_block_id) }}"
        data-confirm="Add these visiting hours to {{ $cellBlock->name }}? Its PDLs will follow the dorm schedule."
        data-confirm-title="Add visiting hours" data-confirm-ok="Add">
    @csrf
    <div class="field-m" style="margin-bottom:16px;">
      <label>Days</label>
      <div class="day-picks">
        @foreach (R::DAY_LABELS as $key => $label)
          <label class="day-pick">
            <input type="checkbox" name="days[]" value="{{ $key }}" data-label="Day: {{ $label }}" @checked(in_array($key, old('days', []), true))>
            {{ substr($label, 0, 3) }}
          </label>
        @endforeach
      </div>
    </div>
    <div class="row cols-3">
      <div class="field-m">
        <label for="vh_start">Start (24-hour)</label>
        <input id="vh_start" type="text" name="start" value="{{ old('start') }}" required inputmode="numeric"
               pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="08:00" title="24-hour time, e.g. 08:00 or 13:30" maxlength="5">
      </div>
      <div class="field-m">
        <label for="vh_end">End (24-hour)</label>
        <input id="vh_end" type="text" name="end" value="{{ old('end') }}" required inputmode="numeric"
               pattern="([01][0-9]|2[0-3]):[0-5][0-9]" placeholder="11:30" title="24-hour time, e.g. 11:30 or 16:30" maxlength="5">
      </div>
      <div class="field-m">
        <label for="vh_cap">Visitors per session</label>
        <input id="vh_cap" type="number" name="max_capacity" value="{{ old('max_capacity', 30) }}" min="1" max="1000" required>
      </div>
    </div>
    <p class="field-hint" style="margin:0 0 14px;">Each day can have one morning session (starting before 12:00) and one afternoon session.</p>
    <button type="submit" class="btn btn-blue">Add Hours</button>
  </form>
</div>
@endsection
