{{--
  Vertical history of audit log entries (newest first).
    $entries      — AuditLog collection (App\Services\ActivityLog::forVisitor)
    $registeredAt    — optional Carbon; adds a "Registered" entry at the bottom
    $registeredLabel — optional text for that entry (default: registered in the app)
--}}
@php $registeredAt = $registeredAt ?? null; $registeredLabel = $registeredLabel ?? 'Registered in the CustodiCore app'; @endphp
@if ($entries->isEmpty() && ! $registeredAt)
  <p class="empty-note">No activity recorded yet.</p>
@else
  <ol class="timeline">
    @foreach ($entries as $log)
      <li class="timeline-item is-{{ \App\Services\ActivityLog::tone($log) }}">
        <div class="timeline-text">{{ $log->description }}</div>
        <div class="timeline-meta">
          {{ \App\Services\ActivityLog::actor($log) }}
          · <time datetime="{{ $log->created_at?->toIso8601String() }}" title="{{ $log->created_at?->format('M j, Y g:i A') }}">{{ $log->created_at?->format('M j, Y g:i A') }}</time>
        </div>
      </li>
    @endforeach
    @if ($registeredAt)
      <li class="timeline-item is-info">
        <div class="timeline-text">{{ $registeredLabel }}</div>
        <div class="timeline-meta"><time>{{ $registeredAt->format(($registeredDateOnly ?? false) ? 'M j, Y' : 'M j, Y g:i A') }}</time></div>
      </li>
    @endif
  </ol>
@endif
