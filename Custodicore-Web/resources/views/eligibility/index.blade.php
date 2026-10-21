@extends('layouts.app')
@section('title', 'Review Queue: Visit Requests — CustodiCore')

@php
  use App\Services\RelationshipRequirements as Req;

  // Raw check results → what they mean for the officer (ok | warn | bad).
  $checkInfo = [
    'identity' => [
      'label' => 'Identity',
      'pass' => ['ok', 'ID verified'],
      'flagged' => ['warn', 'ID not verified yet'],
      'rejected' => ['bad', 'ID rejected'],
    ],
    'relationship' => [
      'label' => 'Relationship',
      'pass' => ['ok', 'Verified'],
      'requires_review' => ['warn', 'Not verified yet'],
    ],
    'history' => [
      'label' => 'Visit History',
      'clean' => ['ok', 'No violations'],
      'has_prior_violations' => ['warn', 'Has active flags'],
    ],
    'restriction' => [
      'label' => 'PDL Restriction',
      'no_restriction' => ['ok', 'None'],
      'restricted' => ['bad', 'PDL is restricted'],
    ],
  ];
  $icons = ['ok' => '✓', 'warn' => '!', 'bad' => '✕'];
@endphp

@section('content')
@include('partials.review-queue-header', ['active' => 'visits'])
<p class="muted-cell queue-help">Visit requests waiting for a decision: everything visitors booked in the app, plus visits whose eligibility check needs a look. Approving confirms the visit and notifies the visitor.</p>

{{-- ================= FILTER TABS ================= --}}
<div class="elig-tabs" role="tablist">
  <a href="{{ route('eligibility.index') }}" class="elig-tab {{ $show === 'all' ? 'is-active' : '' }}" role="tab">
    All waiting <span class="count-chip">{{ $counts['all'] }}</span>
  </a>
  <a href="{{ route('eligibility.index', ['show' => 'ready']) }}" class="elig-tab {{ $show === 'ready' ? 'is-active' : '' }}" role="tab">
    Ready to approve <span class="count-chip is-ok">{{ $counts['ready'] }}</span>
  </a>
  <a href="{{ route('eligibility.index', ['show' => 'waiting']) }}" class="elig-tab {{ $show === 'waiting' ? 'is-active' : '' }}" role="tab">
    Needs attention <span class="count-chip is-warn">{{ $counts['waiting'] }}</span>
  </a>
</div>

@if ($assessments->isEmpty())
  <div class="panel elig-empty">
    <div class="elig-empty-icon">✓</div>
    <div class="empty-title">
      @if ($show === 'ready') No requests are ready to approve yet.
      @elseif ($show === 'waiting') Nothing needs attention right now.
      @else Nothing is waiting for review.
      @endif
    </div>
    <p class="muted-cell">Visit requests appear here as soon as a visitor books one in the app.</p>
  </div>
@else
  @foreach ($assessments as $visit)
    @php
      // Rows are visit requests; $a is their eligibility check (may be missing).
      $a = $visit->eligibilityAssessment;
      $visitor = $visit->visitor;
      $pdl = $visit->pdl;
      $relationship = $visit->relationship;
      $items = $requirements[$visit->visit_request_id] ?? null;
      [$ready, $blockedReason] = \App\Services\ReviewQueue::approvalState($visit, $items);
      $byVisitor = $visit->status === 'assigned';
      $metCount = $items ? collect($items)->where('status', 'met')->count() : 0;
      $total = $items ? count($items) : 0;
      $missing = $items ? Req::unmetLabels($items) : [];
      $checks = [
        'identity' => $a?->identity_check_result,
        'relationship' => $a?->relationship_check_result,
        'history' => $a?->history_check_result,
        'restriction' => $a?->pdl_restriction_check_result,
      ];
      $visitorName = $visitor->full_name ?? 'this visitor';
    @endphp

    <article class="panel elig-card {{ $ready ? 'is-ready' : '' }}">
      {{-- Who is visiting whom, and when --}}
      <header class="elig-head">
        <div class="elig-people">
          <div class="elig-person">
            <div class="thumb elig-visitor-avatar" aria-hidden="true">{{ $visitor ? strtoupper(mb_substr($visitor->full_name, 0, 1)) : '?' }}</div>
            <div>
              <div class="elig-role">Visitor</div>
              @if ($visitor)
                <a class="elig-name" href="{{ route('visitor.show', $visitor->visitor_id) }}">{{ $visitor->full_name }}</a>
              @else
                <span class="elig-name">Unknown visitor</span>
              @endif
              @if ($relationship)
                <div class="muted-cell">{{ $relationship->relationshipLabel() }}</div>
              @endif
            </div>
          </div>

          <div class="elig-arrow" aria-hidden="true">→</div>

          <div class="elig-person">
            @if ($pdl)
              @include('pdl.partials.avatar', ['pdl' => $pdl])
            @endif
            <div>
              <div class="elig-role">PDL</div>
              @if ($pdl)
                <a class="elig-name" href="{{ route('pdl.show', $pdl->pdl_id) }}">{{ $pdl->full_name }}</a>
                <div class="muted-cell">{{ $pdl->pdl_number }}</div>
              @else
                <span class="elig-name">PDL not found</span>
              @endif
            </div>
          </div>
        </div>

        <div class="elig-when">
          @if ($visit->schedule)
            <div class="elig-role">Visit</div>
            <div class="elig-date">{{ $visit->schedule->schedule_date->format('D, M j, Y') }}</div>
            <div class="muted-cell">
              {{ \Carbon\Carbon::parse($visit->schedule->time_slot_start)->format('g:i A') }}–{{ \Carbon\Carbon::parse($visit->schedule->time_slot_end)->format('g:i A') }}
            </div>
          @endif
          <div class="elig-source">{{ $byVisitor ? 'Requested by the visitor in the app' : 'Assigned by staff' }}</div>
          <div class="muted-cell elig-since">{{ $byVisitor ? 'Requested' : 'Assigned' }} {{ $visit->assigned_at?->diffForHumans() ?? $visit->created_at?->diffForHumans() }}</div>
        </div>
      </header>

      {{-- The four automatic checks, in plain words --}}
      @if (! $a)
        <div class="elig-note is-warn" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
          <span>The eligibility check hasn't run for this request yet.</span>
          <form method="POST" action="{{ route('eligibility.run', $visit->visit_request_id) }}" style="margin:0;">
            @csrf
            <button type="submit" class="btn btn-outline-neutral acct-small">Run eligibility check</button>
          </form>
        </div>
      @else
      <div class="elig-checks">
        @foreach ($checks as $key => $result)
          @php [$tone, $text] = $checkInfo[$key][$result] ?? ['warn', ucfirst(str_replace('_', ' ', (string) $result))]; @endphp
          <div class="elig-check is-{{ $tone }}">
            <span class="elig-check-icon" aria-hidden="true">{{ $icons[$tone] }}</span>
            <div>
              <div class="elig-check-label">{{ $checkInfo[$key]['label'] }}</div>
              <div class="elig-check-text">{{ $text }}</div>
            </div>
          </div>
        @endforeach
      </div>
      @endif

      {{-- BJMP documents / confirmations --}}
      @if ($items === null)
        <div class="elig-note is-bad">This request has no PDL relationship on record, so it can't be approved. Reject it, or add the relationship on the visitor's page.</div>
      @else
        <details class="elig-reqs" data-remember-open="elig-reqs-{{ $visit->visit_request_id }}">
          <summary>
            <span class="elig-reqs-title">BJMP requirements</span>
            <span class="elig-progress" aria-hidden="true"><span style="width:{{ $total ? round($metCount / $total * 100) : 0 }}%"></span></span>
            <span class="elig-reqs-count {{ $metCount === $total ? 'is-ok' : '' }}">{{ $metCount }} of {{ $total }} met</span>
            <span class="elig-reqs-toggle">Show details</span>
          </summary>
          @include('partials.relationship-requirements', ['relationship' => $relationship, 'items' => $items])
        </details>
      @endif

      {{-- Decision --}}
      <footer class="elig-actions">
        <div class="elig-status">
          @if ($ready)
            <span class="elig-status-ok">✓ Ready to approve.{{ $byVisitor ? ' Approving confirms the visit and notifies the visitor.' : '' }}</span>
          @elseif ($blockedReason)
            <span class="elig-status-wait">{{ $blockedReason }}</span>
          @endif
        </div>
        <div class="elig-buttons">
          <details class="acct-reject">
            <summary class="btn btn-outline-neutral elig-reject">Reject</summary>
            <form method="POST" action="{{ route('review-queue.visits.reject', $visit->visit_request_id) }}" class="acct-reject-form"
                  data-confirm="Reject this visit request for {{ $visitorName }}?{{ $byVisitor ? ' The time slot is released and the visitor is notified with your reason.' : '' }}"
                  data-confirm-title="Reject visit" data-confirm-ok="Reject" data-confirm-danger>
              @csrf
              <textarea name="reason" required minlength="5" maxlength="255" data-label="Reason (shown to the visitor)"
                        placeholder="e.g. The visitor has an active restriction on this date."></textarea>
              <button type="submit" class="btn btn-outline-neutral elig-reject">Confirm reject</button>
            </form>
          </details>
          <form method="POST" action="{{ route('review-queue.visits.approve', $visit->visit_request_id) }}"
                data-confirm="Approve this visit for {{ $visitorName }}{{ $visit->schedule ? ' on ' . $visit->schedule->schedule_date->format('M j, Y') : '' }}?{{ $byVisitor ? ' The visit is confirmed and the visitor is notified.' : '' }}"
                data-confirm-title="Approve visit" data-confirm-ok="Approve">
            @csrf
            @if ($ready)
              <button type="submit" class="btn btn-blue">Approve</button>
            @else
              <button type="submit" class="btn btn-blue" disabled title="{{ $blockedReason }}">Approve</button>
            @endif
          </form>
        </div>
      </footer>
    </article>
  @endforeach

  @if ($assessments->hasPages())
    <div class="table-footer">
      <span>Showing {{ $assessments->firstItem() }}–{{ $assessments->lastItem() }} of {{ $assessments->total() }}</span>
      <div class="pagination">{{ $assessments->links('partials.pagination') }}</div>
    </div>
  @endif
@endif

{{-- ================= RULES REFERENCE ================= --}}
<details class="panel elig-guide">
  <summary><strong>BJMP visitor requirements</strong> <span class="muted-cell">(reference)</span></summary>
  <div class="req-guide" style="margin-top:12px;">
    <div><strong>Spouse</strong>: marriage certificate + valid ID</div>
    <div><strong>Child of the PDL</strong>: PSA birth certificate + valid ID</div>
    <div><strong>Live-in partner</strong>: CENOMAR of both; with children, the child's PSA birth certificate; without, a witness statement</div>
    <div><strong>Minor visitor</strong>: at least 3 years old, birth certificate, accompanied by a parent/guardian</div>
    <div><strong>Legal guardian</strong>: proof of authorization, only if the PDL has no immediate family available</div>
    <div><strong>Extended relative</strong>: only with official authorization</div>
    <div><strong>Friends / non-relatives</strong>: not allowed</div>
    <div class="muted-cell">Priority: spouse, parents, legal guardian.</div>
  </div>
</details>

<script>
  // Requirements start closed. A card the officer opened stays open across
  // the reload after verifying/rejecting a document (this browser tab only).
  (function () {
    var KEY = 'cc-eligibility-open';
    var open = [];
    try { open = JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) { open = []; }

    document.querySelectorAll('details[data-remember-open]').forEach(function (d) {
      var id = d.getAttribute('data-remember-open');
      if (open.indexOf(id) !== -1) d.open = true;
      d.addEventListener('toggle', function () {
        open = open.filter(function (x) { return x !== id; });
        if (d.open) open.push(id);
        try { sessionStorage.setItem(KEY, JSON.stringify(open)); } catch (e) { /* storage blocked: just don't remember */ }
      });
    });
  })();
</script>
@endsection
