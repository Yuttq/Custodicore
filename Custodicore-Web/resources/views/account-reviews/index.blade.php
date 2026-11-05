@extends('layouts.app')
@section('title', 'Review Queue: Accounts — CustodiCore')

@php
  $tabs = [
    'pending' => ['Waiting for approval', ''],
    'rejected' => ['Returned', ''],
  ];
  $idBadge = ['verified' => ['active', 'Approved'], 'pending' => ['transferred', 'Not checked yet'], 'rejected' => ['rejected', 'Rejected']];
  $typeLabels = \App\Models\VisitorPdlRelationship::TYPE_LABELS;
@endphp

@section('content')
@include('partials.review-queue-header', ['active' => 'accounts'])
<p class="muted-cell queue-help">
  Check the photo, ID and documents, confirm the PDL the visitor will visit, then click <strong>Approve</strong> once.
  It approves the ID, the documents, the account and the PDL relationship together; nothing needs approving again later.
</p>

@if ($walkInsAwaitingDecision->isNotEmpty())
  <div class="panel" style="margin-bottom:16px;border-left:4px solid var(--amber);">
    <div class="panel-header" style="margin-bottom:8px;">
      <h2>Walk-ins waiting for your decision <span class="count-chip">{{ $walkInsAwaitingDecision->count() }}</span></h2>
    </div>
    <p class="muted-cell" style="margin:0 0 10px;">Approved accounts registered at the Front Desk. Complete the walk-in assessment so the Front Desk can book the visit.</p>
    <ul style="margin:0;padding-left:18px;">
      @foreach ($walkInsAwaitingDecision as $walkIn)
        <li style="margin:4px 0;">
          <a class="elig-name" href="{{ route('visitor.show', $walkIn->visitor_id) }}#walk-in">{{ $walkIn->full_name }}</a>
          <span class="muted-cell">· visiting {{ $walkIn->intended_pdl_name ?: '—' }} · registered {{ $walkIn->created_at?->diffForHumans() }}</span>
        </li>
      @endforeach
    </ul>
  </div>
@endif

<div class="elig-tabs" role="tablist">
  @foreach ($tabs as $key => [$label, $chipClass])
    <a href="{{ route('account-reviews.index', $key === 'pending' ? [] : ['tab' => $key]) }}" class="elig-tab {{ $tab === $key ? 'is-active' : '' }}" role="tab">
      {{ $label }} <span class="count-chip {{ $counts[$key] ? $chipClass : '' }}">{{ $counts[$key] }}</span>
    </a>
  @endforeach
</div>

@if ($visitors->isEmpty())
  <div class="panel elig-empty">
    <div class="elig-empty-icon">✓</div>
    <div class="empty-title">
      @if ($tab === 'rejected') No accounts are waiting on the visitor.
      @else No accounts are waiting for approval.
      @endif
    </div>
    <p class="muted-cell">New visitor registrations from the app and walk-ins registered at the Front Desk appear here.</p>
  </div>
@else
  @foreach ($visitors as $visitor)
    @php
      $age = $visitor->date_of_birth?->age;
      // An ID the officer can approve (or already approved).
      $usableId = $visitor->idDocuments->whereIn('verification_status', ['pending', 'verified'])->isNotEmpty();
      // The PDL link: one already on file, or the one to confirm on this card.
      $linked = $visitor->relationships->firstWhere(fn ($r) => $r->verification_status !== 'rejected');
      $needsLink = ! $linked && ! $visitor->isWalkIn();
      $matches = $needsLink ? app(\App\Services\WalkInService::class)->matchingPdls($visitor) : collect();
      $suggested = old('pdl_id') ? \App\Models\Pdl::find(old('pdl_id')) : ($matches->count() === 1 ? $matches->first() : null);
      // The relationship picked in the app, else a guess from what they typed.
      $typeGuess = old('relationship_type', $visitor->intended_relationship_type
        ?: collect($typeLabels)->search(fn ($label) => strcasecmp($label, trim((string) $visitor->relationship_hint)) === 0) ?: null);
      $approveForm = 'approve-' . $visitor->visitor_id;
    @endphp
    <article class="panel elig-card {{ $usableId ? 'is-ready' : '' }}">
      <header class="elig-head">
        <div class="elig-person">
          @include('partials.visitor-avatar', ['visitor' => $visitor, 'class' => 'thumb elig-visitor-avatar'])
          <div>
            <a class="elig-name" href="{{ route('visitor.show', $visitor->visitor_id) }}">{{ $visitor->full_name }}</a>
            @if ($visitor->isWalkIn())
              <span class="badge transferred" title="Registered at the Front Desk without the app"><span class="dot"></span>Walk-in{{ $visitor->created_at?->isToday() && $tab === 'pending' ? ' · waiting at gate' : '' }}</span>
            @endif
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

      @if ($flag = $visitor->longestActiveFlag())
        <div class="elig-note is-warn" style="margin:0 20px 14px;">
          {{ $flag->typeLabel() }}: {{ $flag->durationLabel() }}.
          <a href="{{ route('visitor.show', $visitor->visitor_id) }}#flags" style="color:inherit;font-weight:700;">See flag</a>
        </div>
      @endif

      {{-- What the visitor told us --}}
      <dl class="acct-facts">
        <div><dt>Date of Birth</dt><dd>{{ $visitor->date_of_birth ? $visitor->date_of_birth->format('M j, Y') . " ({$age})" : '—' }}</dd></div>
        <div><dt>Gender</dt><dd>{{ ucfirst($visitor->gender ?? '—') }}</dd></div>
        <div><dt>Email Confirmed</dt><dd>{{ $visitor->account?->email ? ($visitor->account->email_verified_at ? 'Yes' : 'Not yet') : 'No email (walk-in)' }}</dd></div>
        <div><dt>Says They're The PDL's</dt><dd>{{ $visitor->relationship_hint ?: '—' }}</dd></div>
        <div><dt>PDL Name Given (unverified)</dt><dd>{{ $visitor->intended_pdl_name ?: '—' }}</dd></div>
        @if ($visitor->isWalkIn())
          <div><dt>PDL ID Number Given (unverified)</dt><dd>{{ $visitor->intended_pdl_number ?: '—' }}</dd></div>
          <div><dt>Purpose of Visit</dt><dd>{{ $visitor->walkInAssessments->first()?->visit_purpose ?: '—' }}</dd></div>
        @endif
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

      {{-- The visitor's own photo, to compare with the ID picture --}}
      <div class="acct-ids acct-photo">
        <div class="elig-reqs-title" style="margin-bottom:8px;">Visitor Photo</div>
        @if ($visitor->photo_path)
          <div class="acct-photo-row">
            <a href="{{ $visitor->photoUrl() }}" target="_blank" rel="noopener">
              @include('partials.visitor-avatar', ['visitor' => $visitor, 'class' => 'acct-photo-img'])
            </a>
            <p class="muted-cell" style="margin:0;">
              Taken in the app {{ $visitor->photo_uploaded_at?->format('M j, Y H:i') }}.<br>
              Open the ID below and check that it shows the same person.
            </p>
          </div>
        @else
          <p class="muted-cell" style="margin:0;">No photo yet. The visitor takes it in the CustodiCore app when creating their account.</p>
        @endif
      </div>

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
                @elseif ($doc->verification_status === 'pending')
                  <span class="muted-cell">approved together with the account</span>
                @endif
              </div>
              <div class="acct-id-actions">
                @if ($doc->file_path)
                  <a class="btn btn-outline-neutral acct-small" href="{{ route('visitor.ids.file', [$visitor->visitor_id, $doc->visitor_id_doc_id]) }}" target="_blank" rel="noopener">View ID</a>
                @else
                  <span class="muted-cell">No picture (entered at front desk)</span>
                @endif
                @if ($doc->verification_status !== 'rejected')
                  <details class="acct-reject">
                    <summary class="btn btn-outline-neutral acct-small elig-reject">Reject</summary>
                    <form method="POST" action="{{ route('visitor.ids.reject', [$visitor->visitor_id, $doc->visitor_id_doc_id]) }}" class="acct-reject-form"
                          data-confirm="Reject {{ $visitor->full_name }}'s {{ $doc->typeLabel() }}? They will need to upload a new one."
                          data-confirm-title="Reject ID" data-confirm-ok="Reject" data-confirm-danger>
                      @csrf
                      <textarea name="rejection_reason" required minlength="5" maxlength="500" data-label="Why the ID was rejected (shown to the visitor)"
                                placeholder="e.g. The ID photo is unreadable. Please upload a clearer copy."></textarea>
                      <button type="submit" class="btn btn-outline-neutral acct-small elig-reject">Confirm reject</button>
                    </form>
                  </details>
                @endif
              </div>
            </div>
          @endforeach
        @endif
      </div>

      @include('partials.registration-documents', ['visitor' => $visitor])

      {{-- One-step approval: who they will visit, and what Approve will do. --}}
      @php
        $idDoc = $visitor->idDocuments->firstWhere('verification_status', 'pending') ?? $visitor->idDocuments->firstWhere('verification_status', 'verified');
        $panelData = [
          'mode' => $linked ? 'linked' : ($visitor->isWalkIn() ? 'walkin' : 'pick'),
          'id' => $idDoc ? ['label' => $idDoc->typeLabel(), 'approved' => $idDoc->verification_status === 'verified'] : null,
          'linked' => $linked ? ['pdl' => $linked->pdl?->full_name, 'type' => $linked->relationshipLabel()] : null,
          'typeLabels' => $typeLabels,
          'preview' => $needsLink ? app(\App\Services\AccountApproval::class)->preview($visitor) : null,
        ];
      @endphp
      <section class="acct-approve" data-approve-panel data-form="{{ $approveForm }}">
        <script type="application/json" data-approve-data>@json($panelData)</script>
        <div class="acct-approve-grid">
          <div class="acct-approve-main">
            <div class="acct-approve-kicker">One-step approval</div>
            <h3 class="acct-approve-title">Who will {{ $visitor->first_name ?: \Illuminate\Support\Str::before($visitor->full_name, ' ') }} visit?</h3>

            @if ($linked)
              <div class="acct-pdl-chip">
                <span class="acct-pdl-avatar">{{ \Illuminate\Support\Str::of($linked->pdl?->full_name ?? 'P')->explode(' ')->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->join('') }}</span>
                <div><strong>{{ $linked->pdl?->full_name ?? 'PDL' }}</strong><div class="muted-cell">{{ $linked->relationshipLabel() }} · already linked</div></div>
              </div>
            @elseif ($visitor->isWalkIn())
              <p class="muted-cell" style="margin:0;">Walk-in: the PDL is linked automatically from the Front Desk details when approved.</p>
            @else
              <div class="field-m pdl-search" data-pdl-search data-url="{{ route('visitor.relatable-pdls', $visitor->visitor_id) }}">
                <label for="pdl-search-{{ $visitor->visitor_id }}">PDL</label>
                <input type="hidden" name="pdl_id" value="{{ $suggested?->pdl_id }}" data-pdl-id form="{{ $approveForm }}">
                <div class="pdl-search-box">
                  <input type="text" id="pdl-search-{{ $visitor->visitor_id }}" autocomplete="off" form="{{ $approveForm }}"
                         role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="pdl-results-{{ $visitor->visitor_id }}"
                         placeholder="Search name, alias, PDL number or ID"
                         value="{{ $suggested ? $suggested->full_name . ' (' . $suggested->pdl_number . ')' : '' }}">
                  <ul class="pdl-search-results" id="pdl-results-{{ $visitor->visitor_id }}" role="listbox" hidden></ul>
                </div>
                <p class="field-hint" data-pdl-hint>
                  @if ($suggested)
                    ✓ Matches the name they gave ("{{ $visitor->intended_pdl_name }}"). Change it if it's the wrong person.
                  @elseif ($matches->count() > 1)
                    {{ $matches->count() }} PDLs match "{{ $visitor->intended_pdl_name }}". Search and pick the right one.
                  @else
                    No PDL matches "{{ $visitor->intended_pdl_name ?: 'the name given' }}". Search for the PDL.
                  @endif
                </p>
              </div>
              <div class="row cols-2" style="margin:0;">
                <div class="field-m">
                  <label for="rel-type-{{ $visitor->visitor_id }}">Relationship</label>
                  <select id="rel-type-{{ $visitor->visitor_id }}" name="relationship_type" form="{{ $approveForm }}" required data-rel-type>
                    <option value="">— Select —</option>
                    @foreach (\App\Models\VisitorPdlRelationship::RELATIONSHIP_TYPES as $type)
                      <option value="{{ $type }}" @selected($typeGuess === $type)>{{ $typeLabels[$type] }}</option>
                    @endforeach
                  </select>
                  @if ($visitor->intended_relationship_type)
                    <p class="field-hint">As picked by the visitor in the app.</p>
                  @endif
                </div>
                <div class="field-m" data-children-field hidden>
                  <label for="rel-children-{{ $visitor->visitor_id }}">Children Together</label>
                  <select id="rel-children-{{ $visitor->visitor_id }}" name="has_children_together" form="{{ $approveForm }}" data-children>
                    <option value="">Not recorded</option>
                    <option value="1">Has children together</option>
                    <option value="0">No children together</option>
                  </select>
                </div>
              </div>
            @endif
          </div>

          {{-- Filled in by the script below; updates as the PDL and relationship change. --}}
          <aside class="acct-approve-summary">
            <div class="acct-approve-kicker">Approve will</div>
            <ul class="acct-approve-list" data-approve-list></ul>
          </aside>
        </div>
      </section>

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
        <footer class="elig-actions">
          <div class="elig-status" data-approve-status>
            @if (! $usableId)
              <span class="elig-status-wait">Waiting for the visitor to upload a valid ID.</span>
            @elseif ($needsLink)
              <span class="elig-status-ok">Check the ID and documents, confirm the PDL, then approve.</span>
            @else
              <span class="elig-status-ok">✓ Ready. Check the ID and documents, then approve.</span>
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
            <form method="POST" action="{{ route('visitor.approve', $visitor->visitor_id) }}" id="{{ $approveForm }}"
                  data-confirm="Approve {{ $visitor->full_name }}? This approves their ID, documents and account, and their relationship to the PDL. Nothing needs approving again later."
                  data-confirm-title="Approve" data-confirm-ok="Approve">
              @csrf
              @if ($usableId)
                <button type="submit" class="btn btn-blue" data-approve-button>Approve</button>
              @else
                <button type="submit" class="btn btn-blue" disabled title="The visitor has to upload a government ID first">Approve</button>
              @endif
            </form>
          </div>
        </footer>
    </article>
  @endforeach

  @if ($visitors->hasPages())
    <div class="table-footer">
      <span>Showing {{ $visitors->firstItem() }}–{{ $visitors->lastItem() }} of {{ $visitors->total() }}</span>
      <div class="pagination">{{ $visitors->links('partials.pagination') }}</div>
    </div>
  @endif
@endif
@include('partials.pdl-search-script')
<script>
  // "Approve will…" checklist on each card: what one Approve does, given the
  // PDL and relationship picked. A preview; the server decides on submit.
  document.querySelectorAll('[data-approve-panel]').forEach(function (panel) {
    var data = JSON.parse(panel.querySelector('[data-approve-data]').textContent);
    var list = panel.querySelector('[data-approve-list]');
    var form = document.getElementById(panel.dataset.form);
    var button = form ? form.querySelector('[data-approve-button]') : null;
    var pdlId = panel.querySelector('[data-pdl-id]');
    var pdlText = panel.querySelector('.pdl-search-box input[type="text"]');
    var type = panel.querySelector('[data-rel-type]');
    var children = panel.querySelector('[data-children]');
    var childrenField = panel.querySelector('[data-children-field]');

    function add(state, text) {
      var li = document.createElement('li');
      li.className = 'is-' + state;
      li.textContent = text;
      list.appendChild(li);
    }

    function update() {
      list.innerHTML = '';
      var ready = !!data.id;
      add(data.id ? 'ok' : 'bad', data.id ? (data.id.approved ? data.id.label + ' already approved' : 'Approve the ' + data.id.label) : 'No government ID uploaded yet');
      add('ok', 'Approve the account');

      if (data.mode === 'linked') {
        add('ok', 'Approve the link to ' + data.linked.pdl + ' (' + data.linked.type + ') and its documents');
      } else if (data.mode === 'walkin') {
        add('ok', 'Link the PDL from the Front Desk details');
      } else {
        var pdlName = pdlId.value ? pdlText.value.replace(/\s*\([^)]*\)\s*$/, '') : '';
        var t = type.value, info = t ? data.preview.types[t] : null;
        if (childrenField) childrenField.hidden = !(info && info.children);
        if (!pdlName) { add('todo', 'Pick the PDL they will visit'); ready = false; }
        if (!t) { add('todo', 'Pick the relationship'); ready = false; }
        if (pdlName && t) add('ok', 'Link to ' + pdlName + ' as ' + data.typeLabels[t]);

        if (info) {
          var missing = 0;
          var docs = info.docs;
          if (info.children) {
            if (children.value === '1') docs = info.docsWithChildren;
            else if (children.value === '0') docs = info.docsWithoutChildren;
            else { add('warn', 'Children together not recorded yet'); missing++; }
          }
          if (info.blocked) { add('warn', 'Old relationship type: choose the specific relationship'); missing++; }
          docs.forEach(function (doc) {
            if (data.preview.uploaded[doc[0]]) add('ok', 'Approve ' + doc[1]);
            else { add('warn', doc[1] + ' not uploaded'); missing++; }
          });
          info.confirmations.forEach(function (label) { add('warn', label + ': confirm on the visitor page'); missing++; });
          add(missing ? 'warn' : 'done', missing
            ? 'Relationship stays pending until the items above are done'
            : 'Approve the relationship: they can book visits right away');
        }
      }

      if (button) {
        button.disabled = !ready;
        button.title = ready ? '' : 'Pick the PDL and relationship first';
      }
    }

    [pdlId, type, children].forEach(function (el) { if (el) el.addEventListener('change', update); });
    update();
  });
</script>
@endsection
