@extends('layouts.app')
@section('title', 'Visit History — ' . $pdl->full_name)

@section('content')
<div class="topbar">
  <h1>Visit History — {{ $pdl->full_name }} <span class="muted-cell">({{ $pdl->pdl_number }})</span></h1>
  <div class="right">
    <a class="btn btn-outline-neutral" href="{{ route('visitation-tracking.index') }}">← Back to Visitation Tracking</a>
  </div>
</div>

<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Visitors Linked to This PDL</h2></div>

  {{--
    Identity/relationship verification status lives on the visitor's own
    page in Visitor Management now, not duplicated here — this is just a
    quick list + link, since this page is about the PDL's visit history,
    not the visitor's verification detail.
  --}}
  @if ($relationships->isEmpty())
    <p class="empty-note">No visitor relationships registered for this PDL yet.</p>
  @else
    <div style="display:flex;flex-direction:column;gap:8px;">
      @foreach ($relationships as $rel)
      <div style="display:flex;align-items:center;justify-content:between;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);">
        <div>
          <strong>{{ $rel->visitor->full_name ?? 'Unknown visitor' }}</strong>
          <span class="muted-cell">— {{ $rel->relationshipLabel() }}</span>
        </div>
        @if ($rel->visitor)
          <a class="row-action" href="{{ route('visitor.show', $rel->visitor->visitor_id) }}" style="margin-left:auto;">
            View in Visitor Management →
          </a>
        @endif
      </div>
      @endforeach
    </div>
  @endif
</div>

<div class="panel">
  <div class="panel-header"><h2>Log Entries</h2></div>

  @if ($visitRequests->isEmpty())
    <div class="empty-state">
      <div class="empty-icon">🕐</div>
      <div class="empty-title">No Registered Visits Found</div>
      <div class="empty-text">This PDL has not received any authorized visits since intake on {{ $pdl->admission_date->format('M d, Y') }}.</div>
    </div>
  @else
    <table>
      <thead><tr><th>Visitor</th><th>Status</th><th>Assigned</th><th>Confirmed</th><th>Cancelled</th><th>Eligibility</th></tr></thead>
      <tbody>
        @foreach ($visitRequests as $visit)
        <tr>
          <td>{{ $visit->visitor->full_name ?? 'Unknown' }}</td>
          <td>
            <span class="badge {{ $visit->status === 'confirmed' ? 'detained' : 'transferred' }}">
              <span class="dot"></span>{{ strtoupper($visit->status) }}
            </span>
          </td>
          <td class="muted-cell">{{ $visit->assigned_at?->format('M d, Y') }}</td>
          <td class="muted-cell">{{ $visit->confirmed_at?->format('M d, Y') ?? '—' }}</td>
          <td class="muted-cell">
            {{ $visit->cancelled_at?->format('M d, Y') ?? '—' }}
            @if ($visit->cancellation_reason)
              <div class="loc-sub">{{ $visit->cancellation_reason }}</div>
            @endif
          </td>
          <td>
            @if ($visit->eligibilityAssessment)
              <span class="badge {{ match($visit->eligibilityAssessment->overall_result) {
                'eligible' => 'active', 'rejected' => 'released', default => 'transferred'
              } }}">
                <span class="dot"></span>{{ strtoupper(str_replace('_', ' ', $visit->eligibilityAssessment->overall_result)) }}
              </span>
            @else
              <span class="muted-cell">Not checked</span>
            @endif
            <form method="POST" action="{{ route('eligibility.run', $visit->visit_request_id) }}" style="margin-top:4px;">
              @csrf
              <button type="submit" class="row-action">
                {{ $visit->eligibilityAssessment ? 'Re-run →' : 'Run Check →' }}
              </button>
            </form>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>
@endsection