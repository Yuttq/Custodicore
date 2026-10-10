@extends('layouts.app')
@section('title', 'Eligibility Review — CustodiCore')

@section('content')
<div class="topbar">
  <h1>Eligibility Review Queue</h1>
</div>

<div class="panel-table">
  @if ($assessments->isEmpty())
    <p class="empty-note" style="padding:20px;">Nothing waiting for review right now.</p>
  @else
    <table>
      <thead>
        <tr><th>Visitor</th><th>PDL</th><th>Identity</th><th>Relationship</th><th>History</th><th>Restriction</th><th>Requirements</th><th>Reason</th><th></th></tr>
      </thead>
      <tbody>
        @foreach ($assessments as $a)
        @php
          $items = $requirements[$a->assessment_id] ?? null;
          $ready = $items !== null && \App\Services\RelationshipRequirements::allMet($items);
          $relationship = $a->visitRequest->relationship;
        @endphp
        <tr>
          <td>
            @if ($a->visitRequest->visitor)
              <a href="{{ route('visitor.show', $a->visitRequest->visitor->visitor_id) }}" style="color:var(--text);text-decoration:none;font-weight:600;">
                {{ $a->visitRequest->visitor->full_name }}
              </a>
              @if ($relationship)
                <div class="muted-cell">{{ $relationship->relationshipLabel() }}</div>
              @endif
            @else
              —
            @endif
          </td>
          <td>
            @if ($a->visitRequest->pdl)
              <a href="{{ route('pdl.show', $a->visitRequest->pdl->pdl_id) }}" style="color:var(--text);text-decoration:none;font-weight:600;">
                {{ $a->visitRequest->pdl->full_name }}
              </a>
            @else
              —
            @endif
          </td>
          <td><span class="muted-cell">{{ ucfirst($a->identity_check_result) }}</span></td>
          <td><span class="muted-cell">{{ str_replace('_', ' ', ucfirst($a->relationship_check_result)) }}</span></td>
          <td><span class="muted-cell">{{ str_replace('_', ' ', ucfirst($a->history_check_result)) }}</span></td>
          <td><span class="muted-cell">{{ str_replace('_', ' ', ucfirst($a->pdl_restriction_check_result)) }}</span></td>
          <td>
            @if ($items === null)
              <span class="badge rejected"><span class="dot"></span>No relationship</span>
            @elseif ($ready)
              <span class="badge active"><span class="dot"></span>All met</span>
            @else
              <span class="badge transferred"><span class="dot"></span>{{ collect($items)->where('status', 'met')->count() }} of {{ count($items) }} met</span>
            @endif
          </td>
          <td class="muted-cell" style="max-width:220px;">{{ $a->flagged_reason }}</td>
          <td>
            <div style="display:flex;gap:8px;">
              <form method="POST" action="{{ route('eligibility.review', $a->assessment_id) }}"
                    data-confirm="Approve this visit request for {{ $a->visitRequest->visitor->full_name ?? 'this visitor' }}?" data-confirm-title="Approve visit" data-confirm-ok="Approve">
                @csrf
                <input type="hidden" name="decision" value="eligible">
                @if ($ready)
                  <button type="submit" class="row-action" style="color:var(--green);">Approve</button>
                @else
                  <button type="submit" class="row-action" style="color:var(--green);opacity:.45;cursor:not-allowed;" disabled title="All requirements must be met first">Approve</button>
                @endif
              </form>
              <form method="POST" action="{{ route('eligibility.review', $a->assessment_id) }}"
                    data-confirm="Reject this visit request for {{ $a->visitRequest->visitor->full_name ?? 'this visitor' }}?" data-confirm-title="Reject visit" data-confirm-ok="Reject" data-confirm-danger>
                @csrf
                <input type="hidden" name="decision" value="rejected">
                <button type="submit" class="row-action" style="color:var(--red);">Reject</button>
              </form>
            </div>
          </td>
        </tr>
        @if ($items !== null)
          <tr class="req-row">
            <td colspan="9">
              <details {{ $ready ? '' : 'open' }}>
                <summary class="row-action">Visitor requirements</summary>
                @include('partials.relationship-requirements', ['relationship' => $relationship, 'items' => $items])
              </details>
            </td>
          </tr>
        @endif
        @endforeach
      </tbody>
    </table>

    <div class="table-footer">
      <span>Showing {{ $assessments->firstItem() }}–{{ $assessments->lastItem() }} of {{ $assessments->total() }} pending reviews</span>
      <div class="pagination">{{ $assessments->links() }}</div>
    </div>
  @endif
</div>

<div class="panel" style="margin-top:4px;">
  <div class="panel-header"><h2>BJMP visitor requirements</h2></div>
  <div class="req-guide">
    <div><strong>Spouse</strong>: marriage certificate + valid ID</div>
    <div><strong>Child of the PDL</strong>: PSA birth certificate + valid ID</div>
    <div><strong>Live-in partner</strong>: CENOMAR of both; with children, the child's PSA birth certificate; without, a witness statement</div>
    <div><strong>Minor visitor</strong>: at least 3 years old, birth certificate, accompanied by a parent/guardian</div>
    <div><strong>Legal guardian</strong>: proof of authorization, only if the PDL has no immediate family available</div>
    <div><strong>Extended relative</strong>: only with official authorization</div>
    <div><strong>Friends / non-relatives</strong>: not allowed</div>
    <div class="muted-cell">Priority: spouse, parents, legal guardian.</div>
  </div>
</div>
@endsection
