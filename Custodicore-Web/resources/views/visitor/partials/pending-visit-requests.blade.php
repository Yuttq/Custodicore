{{--
  Visitor-submitted visit requests (status `assigned`) awaiting Record Officer review.
  Approve: assigned -> confirmed (needs an `eligible` assessment, future date).
  Reject: assigned -> cancelled (releases the slot).
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
          // The visit date passed while the request was unreviewed. It can no
          // longer be approved, but still holds its seats until rejected here
          // or cancelled by `visits:expire`.
          $isPastDated = $vr->schedule?->schedule_date?->lt(today()) ?? false;
          $seatCount = max($vr->sessionSchedules->count(), 1);
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
              @if ($vr->sessionSchedules->count() > 1)
                · Whole day (one visit)
              @endif
              @foreach ($vr->sessionSchedules->isNotEmpty() ? $vr->sessionSchedules : collect([$vr->schedule]) as $session)
                <div style="font-size:12px;">
                  {{ substr($session->time_slot_start, 0, 5) }}–{{ substr($session->time_slot_end, 0, 5) }}
                  · {{ $session->capacityLabel() }} taken
                </div>
              @endforeach
              @if ($isPastDated)
                <div style="margin-top:6px;">
                  <span class="badge rejected"><span class="dot"></span>VISIT DATE PASSED</span>
                </div>
                <div style="font-size:12px;color:var(--red-text);margin-top:4px;max-width:260px;">
                  This request can no longer be approved. It still holds {{ $seatCount }} reserved {{ \Illuminate\Support\Str::plural('seat', $seatCount) }}:
                  reject it to release {{ $seatCount > 1 ? 'them' : 'it' }} now, or it will expire automatically at the next scheduled expiry run.
                </div>
              @endif
            @else
              —
            @endif
          </td>
          <td>
            @if ($eligibility)
              <span class="badge {{ $eligibilityBadge }}"><span class="dot"></span>{{ strtoupper(str_replace('_', ' ', $eligibility)) }}</span>
              @if ($eligibility === 'flagged_for_review')
                <div style="font-size:12px;margin-top:4px;">
                  <a href="{{ route('eligibility.index') }}" style="color:var(--teal);font-weight:600;">Complete the review</a> before approving.
                </div>
              @elseif ($eligibility === 'rejected')
                <div class="muted-cell" style="font-size:12px;margin-top:4px;">Cannot be approved. Reject this request.</div>
              @endif
            @else
              <span class="muted-cell">Not assessed</span>
              <form method="POST" action="{{ route('eligibility.run', $vr->visit_request_id) }}" style="margin-top:4px;">
                @csrf
                <button type="submit" class="row-action">Run eligibility check</button>
              </form>
            @endif
          </td>
          <td class="muted-cell">{{ optional($vr->assigned_at)->format('M d, Y g:i A') ?? '—' }}</td>
          <td>
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;">
              @unless ($isPastDated)
                <form method="POST" action="{{ route('visitor.visit-requests.approve', [$vr->visitor_id, $vr->visit_request_id]) }}">
                  @csrf
                  <button type="submit" class="row-action" style="color:var(--green);">Approve</button>
                </form>
              @endunless
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
