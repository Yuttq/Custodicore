@extends('layouts.app')
@section('title', 'Visitor Management — CustodiCore')

@section('content')
<div class="topbar">
  <h1>Visitor Management</h1>
</div>

<section class="stats" style="margin-bottom:18px;">
  <a class="stat-card stat-link accent-blue" href="{{ route('visitor.index', ['status' => 'verified']) }}" style="text-decoration:none;color:inherit;" title="Show approved visitors">
    <div class="stat-label">Approved Visitors</div>
    <div class="stat-value">{{ $stats['approved_visitors'] }}</div>
    <div class="muted-cell">can book visits →</div>
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
</section>

@if ($idExpiry)
  <div class="elig-note is-warn" style="margin:0 0 14px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <span>Showing only visitors whose ID has expired or expires within {{ \App\Models\VisitorId::EXPIRES_SOON_DAYS }} days. They have been notified in the app to upload a new one.</span>
    <a href="{{ route('visitor.index') }}" style="color:inherit;font-weight:700;">Show all visitors</a>
  </div>
@endif

@if ($flagged)
  <div class="elig-note is-warn" style="margin:0 0 14px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <span>Showing only visitors with an active flag.</span>
    <a href="{{ route('visitor.index') }}" style="color:inherit;font-weight:700;">Show all visitors</a>
  </div>
@endif

<form class="filters filters-3" method="GET" action="{{ route('visitor.index') }}">
  @if ($flagged)<input type="hidden" name="flagged" value="1">@endif
  @if ($idExpiry)<input type="hidden" name="id_expiry" value="1">@endif
  <div class="field">
    <label for="q">Search</label>
    <div class="input"><input id="q" type="text" name="q" value="{{ $query }}" placeholder="Search by name or contact number"></div>
  </div>
  <div class="field">
    <label for="status">Verification Status</label>
    <select id="status" name="status">
      <option value="" {{ $status === '' ? 'selected' : '' }}>All Statuses</option>
      <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>Pending (Review Queue)</option>
      <option value="walk_in_pending" {{ $status === 'walk_in_pending' ? 'selected' : '' }}>At Front Desk (walk-in)</option>
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
              {{-- The photo taken in the app, or their initial when there is none yet. --}}
              @include('partials.visitor-avatar', ['visitor' => $visitor])
              <a class="full-name" href="{{ route('visitor.show', $visitor->visitor_id) }}">{{ $visitor->full_name }}</a>
            </div>
          </td>
          <td class="muted-cell">{{ $visitor->contact_number }}</td>
          <td>
            @if ($visitor->verification_status === 'pending' && $visitor->isWalkIn())
              {{-- Decided at the Front Desk (they hold the original requirements); nothing for Records to do. --}}
              <span class="badge transferred" title="Walk-in: waiting for the Front Desk to approve or reject"><span class="dot"></span>AT FRONT DESK</span>
            @else
              <span class="badge {{ $visitor->verification_status === 'verified' ? 'active' : ($visitor->verification_status === 'rejected' ? 'released' : 'transferred') }}">
                <span class="dot"></span>{{ ['verified' => 'APPROVED', 'rejected' => 'RETURNED'][$visitor->verification_status] ?? strtoupper($visitor->verification_status) }}
              </span>
            @endif
          </td>
          <td class="muted-cell">
            {{ $visitor->id_documents_count }}
            {{-- The ID in use: expired, or expiring within VisitorId::EXPIRES_SOON_DAYS. --}}
            @php $currentId = $visitor->id_documents_count ? $visitor->currentGovernmentId() : null; @endphp
            @if ($currentId && in_array($currentId->expiryState(), ['expired', 'soon'], true))
              <span class="badge {{ $currentId->expiryState() === 'expired' ? 'rejected' : 'transferred' }}" style="margin-left:6px;" title="{{ $currentId->typeLabel() }}: {{ $currentId->expiryLabel() }}">
                <span class="dot"></span>{{ $currentId->expiryState() === 'expired' ? 'ID expired' : 'ID expires soon' }}
              </span>
            @endif
          </td>
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
