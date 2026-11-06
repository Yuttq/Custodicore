{{--
  Header of the Review Queue: everything waiting for the Record Officer,
  in two steps plus PDL Links. Shared by account-reviews/index (Accounts
  tab), eligibility/index (Visit Requests tab) and pdl-links/index (PDL
  Links tab), which keep their own URLs and filters.
    $active — 'accounts' | 'visits' | 'pdl_links'
--}}
@php
  $queueCounts = \App\Services\ReviewQueue::counts();
@endphp
<div class="topbar">
  <div>
    <h1 style="margin:0;">Review Queue</h1>
    <p class="muted-cell" style="margin:4px 0 0;">Everything waiting for a Records Officer decision, in two steps: first confirm who the visitor is, then check each visit request. PDL Links lists additional PDLs of approved visitors.</p>
  </div>
</div>

<nav class="queue-tabs" aria-label="Review queue">
  <a href="{{ route('account-reviews.index') }}" class="queue-tab {{ $active === 'accounts' ? 'is-active' : '' }}" @if ($active === 'accounts') aria-current="page" @endif>
    <span class="queue-step">Step 1</span>
    <span class="queue-title">Accounts</span>
    <span class="queue-sub">Confirm identity (government ID)</span>
    <span class="queue-count {{ $queueCounts['accounts'] ? 'has-items' : '' }}">{{ $queueCounts['accounts'] }}</span>
  </a>
  <a href="{{ route('eligibility.index') }}" class="queue-tab {{ $active === 'visits' ? 'is-active' : '' }}" @if ($active === 'visits') aria-current="page" @endif>
    <span class="queue-step">Step 2</span>
    <span class="queue-title">Visit Requests</span>
    <span class="queue-sub">Eligibility &amp; BJMP requirements</span>
    <span class="queue-count {{ $queueCounts['visits'] ? 'has-items' : '' }}">{{ $queueCounts['visits'] }}</span>
  </a>
  <a href="{{ route('pdl-links.index') }}" class="queue-tab {{ $active === 'pdl_links' ? 'is-active' : '' }}" @if ($active === 'pdl_links') aria-current="page" @endif>
    <span class="queue-step">Approved visitors</span>
    <span class="queue-title">PDL Links</span>
    <span class="queue-sub">Additional PDLs waiting for approval</span>
    <span class="queue-count {{ $queueCounts['pdl_links'] ? 'has-items' : '' }}">{{ $queueCounts['pdl_links'] }}</span>
  </a>
</nav>
