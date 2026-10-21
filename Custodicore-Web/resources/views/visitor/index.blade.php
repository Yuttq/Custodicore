@extends('layouts.app')
@section('title', 'Visitor Management — CustodiCore')

@section('content')
<div class="topbar">
  <h1>Visitor Management</h1>
</div>

<section class="stats" style="grid-template-columns:repeat(4,1fr);margin-bottom:18px;">
  <a class="stat-card stat-link accent-blue" href="{{ route('visitor.index') }}" style="text-decoration:none;color:inherit;">
    <div class="stat-label">Total Visitors</div>
    <div class="stat-value">{{ $stats['total_visitors'] }}</div>
  </a>
  <a class="stat-card stat-link accent-amber" href="{{ route('account-reviews.index') }}" style="text-decoration:none;color:inherit;" title="Open the Review Queue">
    <div class="stat-label">Pending Verification</div>
    <div class="stat-value">{{ $stats['pending_verification'] }}</div>
    <div class="muted-cell">review accounts →</div>
  </a>
  <a class="stat-card stat-link" href="{{ route('visitor.index', ['flagged' => 1]) }}" style="border-left:3px solid var(--red);text-decoration:none;color:inherit;">
    <div class="stat-label">Flagged</div>
    <div class="stat-value">{{ $stats['flagged'] }}</div>
  </a>
  <a class="stat-card stat-link accent-teal" href="{{ route('eligibility.index') }}" style="text-decoration:none;color:inherit;" title="Open the Review Queue">
    <div class="stat-label">Visit Requests to Review</div>
    <div class="stat-value">{{ $stats['pending_visit_requests'] }}</div>
    <div class="muted-cell">review requests →</div>
  </a>
</section>

@include('visitor.partials.pending-visit-requests', ['showVisitor' => true])

@if ($flagged)
  <div class="elig-note is-warn" style="margin:0 0 14px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <span>Showing only visitors with an active flag.</span>
    <a href="{{ route('visitor.index') }}" style="color:inherit;font-weight:700;">Show all visitors</a>
  </div>
@endif

<form class="filters" method="GET" action="{{ route('visitor.index') }}" style="grid-template-columns:2fr 1.2fr auto;">
  @if ($flagged)<input type="hidden" name="flagged" value="1">@endif
  <div class="field">
    <label for="q">Search</label>
    <div class="input"><input id="q" type="text" name="q" value="{{ $query }}" placeholder="Search by name or contact number"></div>
  </div>
  <div class="field">
    <label for="status">Verification Status</label>
    <select id="status" name="status">
      <option value="" {{ $status === '' ? 'selected' : '' }}>All Statuses</option>
      <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>Pending</option>
      <option value="verified" {{ $status === 'verified' ? 'selected' : '' }}>Verified</option>
      <option value="rejected" {{ $status === 'rejected' ? 'selected' : '' }}>Returned</option>
    </select>
  </div>
  <div class="field">
    <button class="btn btn-blue" type="submit" style="width:100%;justify-content:center;">Apply Filters</button>
  </div>
</form>

<div class="panel-table">
  @if ($visitors->isEmpty())
    <p class="empty-note" style="padding:20px;">No visitors match your current search/filters.</p>
  @else
    <table>
      <thead>
        <tr><th>Visitor</th><th>Contact</th><th>Verification</th><th>ID Docs</th><th>Active Flags</th><th></th></tr>
      </thead>
      <tbody>
        @foreach ($visitors as $visitor)
        <tr>
          <td>
            <div class="name-cell">
              <div class="thumb">{{ strtoupper(substr($visitor->full_name, 0, 2)) }}</div>
              <a class="full-name" href="{{ route('visitor.show', $visitor->visitor_id) }}">{{ $visitor->full_name }}</a>
            </div>
          </td>
          <td class="muted-cell">{{ $visitor->contact_number }}</td>
          <td>
            <span class="badge {{ $visitor->verification_status === 'verified' ? 'active' : ($visitor->verification_status === 'rejected' ? 'released' : 'transferred') }}">
              <span class="dot"></span>{{ ['verified' => 'APPROVED', 'rejected' => 'RETURNED'][$visitor->verification_status] ?? strtoupper($visitor->verification_status) }}
            </span>
          </td>
          <td class="muted-cell">{{ $visitor->id_documents_count }}</td>
          <td class="muted-cell">
            @if ($visitor->active_flags_count > 0)
              <span class="flag">{{ $visitor->active_flags_count }} Active</span>
              @if ($longest = $visitor->longestActiveFlag())
                <div class="muted-cell" style="font-size:11.5px;margin-top:3px;">{{ str_replace('Flagged until', 'Until', $longest->durationLabel()) }}</div>
              @endif
            @else
              —
            @endif
          </td>
          <td><a class="row-action" href="{{ route('visitor.show', $visitor->visitor_id) }}">View</a></td>
        </tr>
        @endforeach
      </tbody>
    </table>

    <div class="table-footer">
      <span>Showing {{ $visitors->firstItem() }}–{{ $visitors->lastItem() }} of {{ $visitors->total() }} visitors</span>
      <div class="pagination">{{ $visitors->links('partials.pagination') }}</div>
    </div>
  @endif
</div>
@endsection
