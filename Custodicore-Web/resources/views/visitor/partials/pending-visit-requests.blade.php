{{--
  Visitor-submitted visit requests (status `assigned`) awaiting Record Officer review.
  Approve: assigned -> confirmed. Reject: assigned -> cancelled (releases the slot).
  Expects: $pendingVisitRequests, $showVisitor (bool — include the visitor column).
--}}
<div class="panel" id="visit-requests" style="margin-bottom:20px;">
  <div class="panel-header">
    <h2>Visit Requests Awaiting Review</h2>
    @if ($pendingVisitRequests->isNotEmpty())
      <span class="badge transferred"><span class="dot"></span>{{ $pendingVisitRequests->count() }} PENDING</span>
    @endif
  </div>

  @if ($pendingVisitRequests->isEmpty())
    <p class="empty-note">No visitor-submitted visit requests are awaiting review.</p>
  @else
    <table>
      <thead>
        <tr>
          @if ($showVisitor)<th>Visitor</th>@endif
          <th>PDL</th>
          <th>Schedule</th>
          <th>Eligibility</th>
          <th>Requested</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach ($pendingVisitRequests as $vr)
        @php
          $eligibility = $vr->eligibilityAssessment?->overall_result;
          $eligibilityBadge = $eligibility === 'eligible' ? 'active' : ($eligibility === 'rejected' ? 'rejected' : 'transferred');
        @endphp
        <tr>
          @if ($showVisitor)
            <td>
              <a class="full-name" href="{{ route('visitor.show', $vr->visitor_id) }}#visit-requests">{{ $vr->visitor?->full_name ?? '—' }}</a>
            </td>
          @endif
          <td>
            {{ $vr->pdl?->full_name ?? '—' }}
            @if ($vr->relationship)
              <div class="muted-cell" style="font-size:12px;">{{ $vr->relationship->relationshipLabel() }}</div>
            @endif
          </td>
          <td class="muted-cell">
            @if ($vr->schedule)
              {{ $vr->schedule->schedule_date->format('M d, Y') }}
              · {{ substr($vr->schedule->time_slot_start, 0, 5) }}–{{ substr($vr->schedule->time_slot_end, 0, 5) }}
              <div style="font-size:12px;">{{ $vr->schedule->capacityLabel() }} taken</div>
            @else
              —
            @endif
          </td>
          <td>
            @if ($eligibility)
              <span class="badge {{ $eligibilityBadge }}"><span class="dot"></span>{{ strtoupper(str_replace('_', ' ', $eligibility)) }}</span>
            @else
              <span class="muted-cell">—</span>
            @endif
          </td>
          <td class="muted-cell">{{ optional($vr->assigned_at)->format('M d, Y g:i A') ?? '—' }}</td>
          <td>
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;">
              <form method="POST" action="{{ route('visitor.visit-requests.approve', [$vr->visitor_id, $vr->visit_request_id]) }}">
                @csrf
                <button type="submit" class="row-action" style="color:var(--green);">Approve</button>
              </form>
              <details>
                <summary class="row-action" style="cursor:pointer;color:var(--red);">Reject</summary>
                <form method="POST" action="{{ route('visitor.visit-requests.reject', [$vr->visitor_id, $vr->visit_request_id]) }}" style="margin-top:10px;min-width:240px;">
                  @csrf
                  <div class="field-m">
                    <label>Reason (shown to the visitor)</label>
                    <textarea name="cancellation_reason" maxlength="255" required placeholder="e.g. The facility is not accepting visits for this PDL on that day."></textarea>
                  </div>
                  <button class="btn btn-outline-neutral" type="submit" style="color:var(--red);margin-top:8px;">Confirm Rejection</button>
                </form>
              </details>
            </div>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>
