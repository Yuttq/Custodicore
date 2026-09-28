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
        <tr><th>Visitor</th><th>PDL</th><th>Identity</th><th>Relationship</th><th>History</th><th>Restriction</th><th>Reason</th><th></th></tr>
      </thead>
      <tbody>
        @foreach ($assessments as $a)
        <tr>
          <td>{{ $a->visitRequest->visitor->full_name ?? '—' }}</td>
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
          <td class="muted-cell" style="max-width:220px;">{{ $a->flagged_reason }}</td>
          <td>
            <div style="display:flex;gap:8px;">
              <form method="POST" action="{{ route('eligibility.review', $a->assessment_id) }}">
                @csrf
                <input type="hidden" name="decision" value="eligible">
                <button type="submit" class="row-action" style="color:var(--green);">Approve →</button>
              </form>
              <form method="POST" action="{{ route('eligibility.review', $a->assessment_id) }}">
                @csrf
                <input type="hidden" name="decision" value="rejected">
                <button type="submit" class="row-action" style="color:var(--red);">Reject →</button>
              </form>
            </div>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>

    <div class="table-footer">
      <span>Showing {{ $assessments->firstItem() }}–{{ $assessments->lastItem() }} of {{ $assessments->total() }} pending reviews</span>
      <div class="pagination">{{ $assessments->links() }}</div>
    </div>
  @endif
</div>
@endsection