@extends('layouts.app')
@section('title', 'Review Queue: PDL Links — CustodiCore')

@section('content')
@include('partials.review-queue-header', ['active' => 'pdl_links'])

{{-- Phase 5B: approved visitors asking to add another PDL by name. --}}
<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Visitor Requests</h2></div>
  <p class="muted-cell queue-help" style="margin-top:0;">
    Approved visitors asking to add another PDL. Open a request to check its documents, find the actual PDL and link it,
    or reject it. Linking creates a pending relationship; you then approve it on the visitor page as usual.
  </p>
  @if ($pdlRequests->isEmpty())
    <p class="empty-note">No visitor requests are waiting.</p>
  @else
    <div class="table-scroll">
      <table>
        <thead>
          <tr><th>Visitor</th><th>PDL name given</th><th>Relationship</th><th>Submitted</th><th></th></tr>
        </thead>
        <tbody>
          @foreach ($pdlRequests as $pdlRequest)
            <tr>
              <td style="font-weight:600;">{{ $pdlRequest->visitor?->full_name ?? '—' }}</td>
              <td>{{ $pdlRequest->pdl_name }}</td>
              <td>{{ $pdlRequest->relationshipLabel() }}</td>
              <td class="muted-cell">{{ $pdlRequest->created_at?->format('M j, Y') ?? '—' }}</td>
              <td><a class="row-action" href="{{ route('pdl-requests.show', $pdlRequest->request_id) }}">Review</a></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @if ($pdlRequests->hasPages())
      <div class="table-footer">
        <span>Showing {{ $pdlRequests->firstItem() }}–{{ $pdlRequests->lastItem() }} of {{ $pdlRequests->total() }}</span>
        <div class="pagination">{{ $pdlRequests->links('partials.pagination') }}</div>
      </div>
    @endif
  @endif
</div>

<h2 style="margin:0 0 6px;">Pending Relationship Links</h2>
<p class="muted-cell queue-help">
  Additional PDLs linked to visitors whose account is already approved. Open a row to review the
  relationship's documents and confirmations on the visitor page, then approve or reject it there.
</p>

@if ($links->isEmpty())
  <div class="panel elig-empty">
    <div class="elig-empty-icon">✓</div>
    <div class="empty-title">No PDL links are waiting for approval.</div>
    <p class="muted-cell">PDLs added to an approved visitor appear here until the relationship is approved or rejected.</p>
  </div>
@else
  <div class="panel">
    <div class="table-scroll">
      <table>
        <thead>
          <tr><th>Visitor</th><th>PDL</th><th>Relationship</th><th>Status</th><th>Added</th><th></th></tr>
        </thead>
        <tbody>
          @foreach ($links as $link)
            <tr>
              <td style="font-weight:600;">{{ $link->visitor?->full_name ?? '—' }}</td>
              <td>{{ $link->pdl?->full_name ?? 'PDL not found' }}</td>
              <td>{{ $link->relationshipLabel() }}</td>
              <td><span class="badge transferred"><span class="dot"></span>Pending</span></td>
              {{-- created_at is not cast to a date on this model. --}}
              <td class="muted-cell">{{ $link->created_at ? \Illuminate\Support\Carbon::parse($link->created_at)->format('M j, Y') : '—' }}</td>
              <td>
                <a class="row-action" href="{{ route('visitor.show', $link->visitor_id) }}#relationships">Review</a>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @if ($links->hasPages())
      <div class="table-footer">
        <span>Showing {{ $links->firstItem() }}–{{ $links->lastItem() }} of {{ $links->total() }}</span>
        <div class="pagination">{{ $links->links('partials.pagination') }}</div>
      </div>
    @endif
  </div>
@endif
@include('partials.live-refresh')
@endsection
