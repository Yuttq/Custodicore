@extends('layouts.app')
@section('title', 'Activity Log — CustodiCore')

@section('content')
<div class="topbar">
  <div>
    <h1 style="margin:0;">Activity Log</h1>
    <p class="muted-cell" style="margin:4px 0 0;">Everything done in the Records area: who did it and when. Entries can't be edited or deleted.</p>
  </div>
</div>

<form class="filters log-filters" method="GET" action="{{ route('activity-log.index') }}">
  <div class="field">
    <label for="q">Search</label>
    <div class="input">
      <input id="q" type="text" name="q" value="{{ $q }}" maxlength="100" placeholder="Name, case number, action…">
    </div>
  </div>
  <div class="field">
    <label for="category">Type</label>
    <select id="category" name="category">
      <option value="">All types</option>
      @foreach (\App\Services\ActivityLog::CATEGORIES as $key => $cat)
        <option value="{{ $key }}" {{ $category === $key ? 'selected' : '' }}>{{ $cat['label'] }}</option>
      @endforeach
    </select>
  </div>
  <div class="field">
    <label for="who">Done by</label>
    <select id="who" name="who">
      <option value="all" {{ $who === 'all' ? 'selected' : '' }}>Everyone</option>
      <option value="me" {{ $who === 'me' ? 'selected' : '' }}>Only me</option>
    </select>
  </div>
  <div class="field">
    <label for="from">From</label>
    <input id="from" type="date" name="from" value="{{ $from }}" max="{{ now()->toDateString() }}">
  </div>
  <div class="field">
    <label for="to">To</label>
    <input id="to" type="date" name="to" value="{{ $to }}" max="{{ now()->toDateString() }}">
  </div>
  <div class="field" style="display:flex;gap:8px;">
    <button class="btn btn-blue" type="submit">Filter</button>
    @if ($q || $category || $who !== 'all' || $from || $to)
      <a class="btn btn-outline-neutral" href="{{ route('activity-log.index') }}">Clear</a>
    @endif
  </div>
</form>

<div class="panel-table">
  @if ($logs->isEmpty())
    <p class="empty-note" style="padding:20px;">No activity matches these filters.</p>
  @else
    @php $lastDay = null; @endphp
    @foreach ($logs as $log)
      @php
        $day = $log->created_at?->isToday() ? 'Today' : ($log->created_at?->isYesterday() ? 'Yesterday' : $log->created_at?->format('l, M j, Y'));
        $tone = \App\Services\ActivityLog::tone($log);
        $cat = \App\Services\ActivityLog::categoryOf($log->record_type);
      @endphp
      @if ($day !== $lastDay)
        <div class="log-day">{{ $day }}</div>
        @php $lastDay = $day; @endphp
      @endif
      <div class="log-row">
        <div class="log-time">{{ $log->created_at?->format('H:i') }}</div>
        <div>
          <div class="log-desc"><span class="log-dot is-{{ $tone }}" aria-hidden="true"></span>{{ $log->description }}</div>
          <div class="log-sub">
            {{ \App\Services\ActivityLog::actor($log) }}
            @if ($cat) · {{ \App\Services\ActivityLog::CATEGORIES[$cat]['label'] }} @endif
          </div>
        </div>
        <div>
          @if (isset($links[$log->audit_log_id]))
            <a class="row-action" href="{{ $links[$log->audit_log_id] }}">Open</a>
          @endif
        </div>
      </div>
    @endforeach

    <div class="table-footer">
      <span>Showing {{ $logs->firstItem() }}–{{ $logs->lastItem() }} of {{ $logs->total() }} entries</span>
      <div class="pagination">{{ $logs->links('partials.pagination') }}</div>
    </div>
  @endif
</div>
@endsection
