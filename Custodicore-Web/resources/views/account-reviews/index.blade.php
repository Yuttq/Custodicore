@extends('layouts.app')
@section('title', 'Review Queue: Accounts — CustodiCore')

@php
  $tabs = [
    'pending' => ['Waiting for approval', ''],
    'recheck' => ['Approved without verified ID', 'is-warn'],
    'rejected' => ['Returned', ''],
  ];
  $idBadge = ['verified' => ['active', 'Verified'], 'pending' => ['transferred', 'Not checked yet'], 'rejected' => ['rejected', 'Rejected']];
@endphp

@section('content')
@include('partials.review-queue-header', ['active' => 'accounts'])
<p class="muted-cell queue-help">
  Open each ID, check it matches the visitor, verify it, then approve the account.
  Relationship documents (marriage certificate, CENOMAR, …) are checked per visit request in Step 2.
</p>

<div class="elig-tabs" role="tablist">
  @foreach ($tabs as $key => [$label, $chipClass])
    <a href="{{ route('account-reviews.index', $key === 'pending' ? [] : ['tab' => $key]) }}" class="elig-tab {{ $tab === $key ? 'is-active' : '' }}" role="tab">
      {{ $label }} <span class="count-chip {{ $counts[$key] ? $chipClass : '' }}">{{ $counts[$key] }}</span>
    </a>
  @endforeach
</div>

@if ($tab === 'recheck' && $visitors->isNotEmpty())
  <div class="elig-note is-warn" style="margin:0 0 16px;">
    These visitors were approved before a verified government ID was required. Please open their ID and verify or reject it.
    If an ID is fake or doesn't match, reject it and contact the visitor.
  </div>
@endif

@if ($visitors->isEmpty())
  <div class="panel elig-empty">
    <div class="elig-empty-icon">✓</div>
    <div class="empty-title">
      @if ($tab === 'recheck') Every approved visitor has a verified ID.
      @elseif ($tab === 'rejected') No accounts are waiting on the visitor.
      @else No accounts are waiting for approval.
      @endif
    </div>
    <p class="muted-cell">New visitor registrations from the app appear here.</p>
  </div>
@else
  @foreach ($visitors as $visitor)
    @php
      $hasVerifiedId = $visitor->hasVerifiedId();
      $age = $visitor->date_of_birth?->age;
      $pendingIds = $visitor->idDocuments->where('verification_status', 'pending')->count();
    @endphp
    <article class="panel elig-card {{ $hasVerifiedId ? 'is-ready' : '' }}">
      <header class="elig-head">
        <div class="elig-person">
          <div class="thumb elig-visitor-avatar" aria-hidden="true">{{ strtoupper(mb_substr($visitor->full_name, 0, 1)) }}</div>
          <div>
            <a class="elig-name" href="{{ route('visitor.show', $visitor->visitor_id) }}">{{ $visitor->full_name }}</a>
            <div class="muted-cell">
              {{ $visitor->account?->email ?? 'No email' }}
              @if ($visitor->contact_number) · {{ $visitor->contact_number }} @endif
            </div>
          </div>
        </div>
        <div class="elig-when">
          <div class="elig-role">Registered</div>
          <div class="elig-date">{{ $visitor->created_at?->format('M j, Y') ?? '—' }}</div>
          <div class="muted-cell elig-since">{{ $visitor->created_at?->diffForHumans() }}</div>
        </div>
      </header>

      {{-- What the visitor told us --}}
      <dl class="acct-facts">
        <div><dt>Date of Birth</dt><dd>{{ $visitor->date_of_birth ? $visitor->date_of_birth->format('M j, Y') . " ({$age})" : '—' }}</dd></div>
        <div><dt>Gender</dt><dd>{{ ucfirst($visitor->gender ?? '—') }}</dd></div>
        <div><dt>Email Confirmed</dt><dd>{{ $visitor->account?->email_verified_at ? 'Yes' : 'Not yet' }}</dd></div>
        <div><dt>Says They're The PDL's</dt><dd>{{ $visitor->relationship_hint ?: '—' }}</dd></div>
        <div><dt>PDL Name Given (unverified)</dt><dd>{{ $visitor->intended_pdl_name ?: '—' }}</dd></div>
        <div>
          <dt>Linked PDLs</dt>
          <dd>
            @forelse ($visitor->relationships as $rel)
              {{ $rel->pdl?->full_name ?? 'PDL' }} ({{ $rel->relationshipLabel() }})@if (! $loop->last), @endif
            @empty
              None yet
            @endforelse
          </dd>
        </div>
        @if ($age !== null && $age < 18)
          <div><dt>Note</dt><dd style="color:var(--amber-text);">Minor visitor</dd></div>
        @endif
      </dl>

      {{-- Government IDs --}}
      <div class="acct-ids">
        <div class="elig-reqs-title" style="margin-bottom:8px;">Government ID</div>
        @if ($visitor->idDocuments->isEmpty())
          <p class="muted-cell" style="margin:0;">No ID uploaded yet. The visitor uploads it from the CustodiCore app.</p>
        @else
          @foreach ($visitor->idDocuments as $doc)
            @php [$badgeClass, $badgeText] = $idBadge[$doc->verification_status] ?? ['released', ucfirst($doc->verification_status)]; @endphp
            <div class="acct-id-row">
              <div class="acct-id-main">
                <strong>{{ $doc->typeLabel() }}</strong>
                <span class="muted-cell">
                  @if ($doc->id_number && $doc->id_number !== 'PENDING') No. {{ $doc->id_number }} · @endif
                  uploaded {{ $doc->uploaded_at?->format('M j, Y') }}
                </span>
                <span class="badge {{ $badgeClass }}"><span class="dot"></span>{{ $badgeText }}</span>
                @if ($doc->verification_status === 'verified' && $doc->verifiedBy)
                  <span class="muted-cell">by {{ $doc->verifiedBy->full_name }}</span>
                @endif
              </div>
              <div class="acct-id-actions">
                @if ($doc->file_path)
                  <a class="btn btn-outline-neutral acct-small" href="{{ route('visitor.ids.file', [$visitor->visitor_id, $doc->visitor_id_doc_id]) }}" target="_blank" rel="noopener">View ID</a>
                @else
                  <span class="muted-cell">No picture (entered at front desk)</span>
                @endif
                @if ($doc->verification_status !== 'verified')
                  <form method="POST" action="{{ route('visitor.ids.verify', [$visitor->visitor_id, $doc->visitor_id_doc_id]) }}"
                        data-confirm="Mark {{ $visitor->full_name }}'s {{ $doc->typeLabel() }} as verified? Only do this after opening it and checking it matches the visitor."
                        data-confirm-title="Verify ID" data-confirm-ok="Verify">
                    @csrf
                    <button type="submit" class="btn btn-outline-neutral acct-small" style="color:var(--green);">Verify</button>
                  </form>
                @endif
                @if ($doc->verification_status !== 'rejected')
                  <form method="POST" action="{{ route('visitor.ids.reject', [$visitor->visitor_id, $doc->visitor_id_doc_id]) }}"
                        data-confirm="Reject {{ $visitor->full_name }}'s {{ $doc->typeLabel() }}? They will need to upload a new one."
                        data-confirm-title="Reject ID" data-confirm-ok="Reject" data-confirm-danger>
                    @csrf
                    <button type="submit" class="btn btn-outline-neutral acct-small elig-reject">Reject</button>
                  </form>
                @endif
              </div>
            </div>
          @endforeach
        @endif
      </div>

      @include('partials.registration-documents', ['visitor' => $visitor])

      @if ($tab === 'rejected' && $visitor->rejection_reason)
        <div class="elig-note is-warn">Returned. What to fix: {{ $visitor->rejection_reason }}</div>
      @endif

      {{-- What has happened to this account so far --}}
      @php $history = \App\Services\ActivityLog::forVisitor($visitor, 20); @endphp
      <details class="acct-history">
        <summary>History ({{ $history->count() + 1 }})</summary>
        <div class="timeline-scroll">
          @include('partials.activity-timeline', ['entries' => $history, 'registeredAt' => $visitor->created_at])
        </div>
      </details>

      {{-- Decision --}}
      @if ($tab !== 'recheck')
        <footer class="elig-actions">
          <div class="elig-status">
            @if ($hasVerifiedId)
              <span class="elig-status-ok">✓ ID verified. The account can be approved.</span>
            @elseif ($pendingIds)
              <span class="elig-status-wait">Open and verify the ID before approving.</span>
            @else
              <span class="elig-status-wait">Waiting for the visitor to upload a valid ID.</span>
            @endif
          </div>
          <div class="elig-buttons">
            <details class="acct-reject">
              <summary class="btn btn-outline-neutral elig-reject">Return</summary>
              <form method="POST" action="{{ route('visitor.reject', $visitor->visitor_id) }}" class="acct-reject-form"
                    data-confirm="Return {{ $visitor->full_name }}'s account for correction? They will be notified of what to fix and can resubmit."
                    data-confirm-title="Return account" data-confirm-ok="Return" data-confirm-danger>
                @csrf
                <textarea name="rejection_reason" required minlength="5" maxlength="500" data-label="What to fix (shown to the visitor)"
                          placeholder="e.g. The ID photo is unreadable. Please upload a clearer copy."></textarea>
                <button type="submit" class="btn btn-outline-neutral elig-reject">Confirm return</button>
              </form>
            </details>
            <form method="POST" action="{{ route('visitor.approve', $visitor->visitor_id) }}"
                  data-confirm="Approve {{ $visitor->full_name }}'s account? They will be notified and can start requesting visits once a PDL relationship is verified."
                  data-confirm-title="Approve account" data-confirm-ok="Approve">
              @csrf
              @if ($hasVerifiedId)
                <button type="submit" class="btn btn-blue">Approve account</button>
              @else
                <button type="submit" class="btn btn-blue" disabled title="Verify a government ID first">Approve account</button>
              @endif
            </form>
          </div>
        </footer>
      @endif
    </article>
  @endforeach

  @if ($visitors->hasPages())
    <div class="table-footer">
      <span>Showing {{ $visitors->firstItem() }}–{{ $visitors->lastItem() }} of {{ $visitors->total() }}</span>
      <div class="pagination">{{ $visitors->links('partials.pagination') }}</div>
    </div>
  @endif
@endif
@endsection
