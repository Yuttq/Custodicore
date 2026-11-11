@extends('layouts.app')
@section('title', 'PDL Profile — CustodiCore')

@php
  $statusLabels = ['active' => 'Active', 'released' => 'Released', 'transferred' => 'Transferred', 'deceased' => 'Deceased'];
  $classificationLabels = ['drug_related' => 'Drug-related', 'non_drug_related' => 'Non-drug-related'];
  $restrictionLabel = fn ($type) => \Illuminate\Support\Str::of($type)->replace('_', ' ')->title();
  $activeRestrictions = $pdl->restrictions->filter->isActive();
  $nameParts = $pdl->nameParts();

  // Open the edit form again after a failed save (wrong password, invalid
  // field), and the matching "Add …" form after its own validation error.
  $profileFields = ['first_name', 'middle_name', 'last_name', 'alias', 'gender', 'date_of_birth', 'admission_date', 'cell_block', 'custody_status', 'photo', 'current_password'];
  $editing = $errors->hasAny($profileFields);
  // Errors from a record's Update window re-open that window instead (old('editing_record')).
  $openLegal = ! old('editing_record') && $errors->hasAny(['case_number', 'offense_code', 'offense_other', 'court', 'case_status', 'date_filed',
    'next_hearing_at', 'hearing_notes', 'convicted_on', 'sentence_years', 'sentence_months', 'sentence_days', 'remarks']);
  $openDisciplinary = $errors->hasAny(['incident_date', 'description', 'action_taken']);
  $openRestriction = $errors->hasAny(['restriction_type', 'start_date', 'end_date', 'reason', 'disciplinary_record_id']);
@endphp

@section('content')
<div class="topbar">
  <a class="row-action" href="{{ route('pdl.index') }}">← Back to PDL Management</a>
</div>

{{-- ================= SUMMARY HEADER ================= --}}
<div class="panel pdl-hero">
  <div class="pdl-hero-main">
    @include('pdl.partials.avatar', ['pdl' => $pdl, 'class' => 'pdl-photo-lg'])
    <div class="pdl-hero-text">
      <h1 class="pdl-hero-name">{{ $pdl->full_name }}</h1>
      <div class="pdl-hero-sub">
        {{ $pdl->pdl_number }}
        @if ($pdl->alias) · alias "{{ $pdl->alias }}" @endif
      </div>
      <div class="pdl-hero-badges">
        <span class="badge {{ $pdl->custody_status }}"><span class="dot"></span>{{ $statusLabels[$pdl->custody_status] ?? ucfirst($pdl->custody_status) }}</span>
        <span class="badge {{ $eligibilityStatus['class'] }}"><span class="dot"></span>{{ $eligibilityStatus['label'] }}</span>
        {{-- Visiting availability from the dorm schedule (or classification days). --}}
        <span class="badge {{ \App\Services\PdlVisitingAvailability::badgeClass($visiting['state']) }}" title="{{ $visiting['source'] }}"><span class="dot"></span>{{ $visiting['label'] }}</span>
        @if ($activeRestrictions->isNotEmpty())
          <a href="#restrictions" class="badge restricted" style="text-decoration:none;">
            <span class="dot"></span>{{ $activeRestrictions->count() }} active {{ \Illuminate\Support\Str::plural('restriction', $activeRestrictions->count()) }}
          </a>
        @endif
      </div>
    </div>
    <div class="pdl-hero-actions">
      <button type="button" class="btn btn-blue" data-edit-open {{ $editing ? 'hidden' : '' }}>Edit Profile</button>
      <a class="btn btn-outline-neutral" href="{{ route('visitation-tracking.show', $pdl->pdl_id) }}">Visit History</a>
    </div>
  </div>

  {{-- Everything the old read-only "Profile Details" panel showed lives here now;
       the full-width items (offense, visiting hours) go last so they can span both columns on phones. --}}
  <dl class="pdl-facts">
    <div><dt>Cell/Block</dt><dd>{{ $pdl->cell_block ?? 'Not assigned' }}</dd></div>
    <div><dt>Classification</dt><dd>{{ $classificationLabels[$pdl->classification] ?? '—' }}</dd></div>
    <div><dt>Gender</dt><dd>{{ $pdl->gender ? ucfirst($pdl->gender) : '—' }}</dd></div>
    <div>
      <dt>Age</dt>
      <dd>{{ $pdl->date_of_birth ? $pdl->date_of_birth->age . ' yrs' : '—' }}@if ($pdl->date_of_birth) <span class="muted-cell">· {{ $pdl->date_of_birth->format('M j, Y') }}</span>@endif</dd>
    </div>
    <div>
      <dt>Admitted</dt>
      <dd>
        {{ $pdl->admission_date?->format('M j, Y') ?? '—' }}
        @if ($pdl->admission_date && $pdl->custody_status === 'active')
          <span class="muted-cell">({{ $pdl->admission_date->diffForHumans(null, true, false, 2) }})</span>
        @endif
      </dd>
    </div>
    <div><dt>Registered By</dt><dd>{{ $pdl->registeredBy?->full_name ?? '—' }}</dd></div>
    <div class="is-wide"><dt>Offense</dt><dd>{{ $pdl->primaryOffense() ?? 'No case on file' }}@if ($pdl->legalRecords->count() > 1) <span class="muted-cell">+{{ $pdl->legalRecords->count() - 1 }} more</span>@endif</dd></div>
    <div class="is-wide">
      <dt>Visiting Hours</dt>
      <dd>{{ $visiting['weekly'] ?: 'No schedule set' }}<div class="muted-cell" style="font-weight:500;font-size:11.5px;">{{ $visiting['source'] }}</div></dd>
    </div>
  </dl>
  @if ($pdl->first_name === null && $pdl->last_name === null)
    <p class="field-hint" style="margin:10px 0 0;">Only a full name is on file for this record. Open <strong>Edit Profile</strong> to confirm the first, middle and last name.</p>
  @endif
</div>

{{-- ================= EDIT PROFILE (shown only while editing) ================= --}}
<div class="panel" style="margin-bottom:14px;" id="profile" data-profile-panel {{ $editing ? '' : 'hidden' }}>
  <div class="panel-header">
    <h2>Edit Profile</h2>
    <span class="muted-cell">Changes are saved after you confirm with your password.</span>
  </div>

  {{-- Final step on save: the officer re-enters their own password (data-password-confirm → partials/password-confirm). --}}
  <form method="POST" action="{{ route('pdl.update', $pdl->pdl_id) }}" enctype="multipart/form-data" data-profile-edit
        data-password-confirm="Save changes to {{ $pdl->full_name }}'s profile? Enter your password to confirm.">
    @csrf
    @method('PUT')

    <p class="section-title"><span class="icon">@include('partials.icon', ['name' => 'camera'])</span> Photo</p>
    <div class="row">
      @include('pdl.partials.photo-field', ['pdl' => $pdl])
    </div>

    <p class="section-title"><span class="icon">@include('partials.icon', ['name' => 'user'])</span> Personal Details</p>
    @if ($pdl->first_name === null && $pdl->last_name === null)
      <p class="field-hint" style="margin:0 0 12px 0;">This record only has a full name on file. The name fields below are a best guess. Please check them before saving.</p>
    @endif
    <div class="row cols-3">
      <div class="field-m"><label>First Name</label><input type="text" name="first_name" value="{{ old('first_name', $nameParts['first']) }}" maxlength="100" required pattern="[A-Za-zÀ-ÿÑñ][A-Za-zÀ-ÿÑñ .'\-]*" title="Letters, spaces, dots, apostrophes and dashes only"></div>
      <div class="field-m"><label>Middle Name (optional)</label><input type="text" name="middle_name" value="{{ old('middle_name', $nameParts['middle']) }}" maxlength="100" pattern="[A-Za-zÀ-ÿÑñ][A-Za-zÀ-ÿÑñ .'\-]*" title="Letters, spaces, dots, apostrophes and dashes only"></div>
      <div class="field-m"><label>Last Name</label><input type="text" name="last_name" value="{{ old('last_name', $nameParts['last']) }}" maxlength="100" required pattern="[A-Za-zÀ-ÿÑñ][A-Za-zÀ-ÿÑñ .'\-]*" title="Letters, spaces, dots, apostrophes and dashes only"></div>
    </div>
    <div class="row cols-3">
      <div class="field-m"><label>Alias</label><input type="text" name="alias" value="{{ old('alias', $pdl->alias) }}" maxlength="150"></div>
      <div class="field-m">
        <label>Gender</label>
        <select name="gender">
          {{-- old() first so a rejected password doesn't throw away the officer's edits --}}
          <option value="male" {{ old('gender', $pdl->gender) === 'male' ? 'selected' : '' }}>Male</option>
          <option value="female" {{ old('gender', $pdl->gender) === 'female' ? 'selected' : '' }}>Female</option>
          <option value="other" {{ old('gender', $pdl->gender) === 'other' ? 'selected' : '' }}>Other</option>
        </select>
      </div>
      <div class="field-m"><label>Date of Birth</label><input type="date" name="date_of_birth" value="{{ old('date_of_birth', optional($pdl->date_of_birth)->format('Y-m-d')) }}" required min="1900-01-02" max="{{ now()->subYears(18)->toDateString() }}" title="The PDL must be at least 18 years old"></div>
    </div>

    <p class="section-title"><span class="icon">@include('partials.icon', ['name' => 'building'])</span> Custody Details</p>
    <div class="row cols-3">
      <div class="field-m">
        {{-- Drives the visiting-day rule (drug_related -> Thu/Sat, non_drug_related -> Fri/Sun).
             Set from the PDL's cases (Pdl::refreshClassification), not edited here. --}}
        <label>Classification</label>
        <div class="readonly-field">{{ $classificationLabels[$pdl->classification] ?? '—' }}</div>
        <p class="field-hint">Set automatically from the offense. Add a case under Legal Records to change it.</p>
      </div>
      <div class="field-m"><label>Admission Date</label><input type="date" name="admission_date" value="{{ old('admission_date', optional($pdl->admission_date)->format('Y-m-d')) }}" required max="{{ now()->toDateString() }}"></div>
      <div class="field-m">
        <label>Custody Status</label>
        <select name="custody_status">
          @foreach ($statusLabels as $value => $label)
            <option value="{{ $value }}" {{ old('custody_status', $pdl->custody_status) === $value ? 'selected' : '' }}>{{ $label }}</option>
          @endforeach
        </select>
      </div>
    </div>
    <div class="row">
      @include('pdl.partials.cell-block-select', ['selected' => old('cell_block', $pdl->cell_block), 'current' => $pdl->cell_block, 'required' => false, 'classification' => $pdl->classification, 'pdl' => $pdl])
    </div>

    <div class="form-actions">
      <button class="btn btn-blue" type="submit">Save Profile Changes</button>
      <button class="btn btn-outline-neutral" type="button" data-edit-cancel>Cancel</button>
    </div>
  </form>
</div>

{{-- ================= VISITORS (linked to this PDL) ================= --}}
@php
  $rels = $pdl->visitorRelationships;
  $relCounts = $rels->countBy('verification_status');
  $relBadge = ['verified' => ['active', 'Approved'], 'pending' => ['transferred', 'Pending'], 'rejected' => ['rejected', 'Rejected']];
@endphp
<div class="panel" style="margin-bottom:20px;" id="visitors">
  <div class="panel-header">
    <h2>Visitors <span class="count-chip">{{ $rels->count() }}</span></h2>
    @if ($rels->isNotEmpty())
      <span class="muted-cell">
        {{ $relCounts['verified'] ?? 0 }} approved · {{ $relCounts['pending'] ?? 0 }} pending
        @if ($relCounts['rejected'] ?? 0) · {{ $relCounts['rejected'] }} rejected @endif
      </span>
    @endif
  </div>

  @if ($rels->isEmpty())
    <p class="empty-note">No visitors are linked to {{ $pdl->full_name }} yet. Visitors are linked when their account is approved in the Review Queue.</p>
  @else
    <div class="rec-list">
      @foreach ($rels as $rel)
        @php
          $visitor = $rel->visitor;
          [$badgeClass, $badgeText] = $relBadge[$rel->verification_status] ?? ['released', ucfirst($rel->verification_status)];
          $vv = $visitorVisits[$rel->visitor_id] ?? null;
          $next = $vv['next'] ?? null;
          $last = $vv['last'] ?? null;
        @endphp
        <div class="rec rec-person">
          @include('partials.visitor-avatar', ['visitor' => $visitor])
          <div class="rec-body">
            <div class="rec-head">
              @if ($visitor)
                <a class="rec-title full-name" href="{{ route('visitor.show', $visitor->visitor_id) }}#relationships">{{ $visitor->full_name }}</a>
              @else
                <span class="rec-title muted-cell">Visitor not found</span>
              @endif
              <span class="badge {{ $badgeClass }}"><span class="dot"></span>{{ $badgeText }}</span>
            </div>
            <div class="rec-meta">
              {{ $rel->relationshipLabel() }}
              @if ($rel->isHighPriority()) <span class="rec-tag">High priority</span>@endif
              @if ($visitor && $visitor->contact_number && $visitor->contact_number !== 'N/A') · {{ $visitor->contact_number }}@endif
            </div>
            <div class="rec-meta">
              @if ($next)
                Next: <strong>{{ $next->schedule->schedule_date->format('M j') }}, {{ substr((string) $next->schedule->time_slot_start, 0, 5) }}</strong>
                ({{ $next->status === 'confirmed' ? 'confirmed' : 'awaiting review' }})
              @else
                No visit booked
              @endif
              ·
              @if ($last)
                Last: {{ $last->schedule?->schedule_date?->format('M j, Y') }} ({{ $vv['completed'] }} {{ $vv['completed'] === 1 ? 'visit' : 'visits' }})
              @else
                No visits yet
              @endif
            </div>
          </div>
        </div>
      @endforeach
    </div>
  @endif
</div>

{{-- ================= CUSTODY HISTORY (was its own page) ================= --}}
<div class="panel" style="margin-bottom:20px;" id="custody-history">
  <div class="panel-header">
    <h2>Custody History</h2>
    <span class="muted-cell">Status and cell changes, newest first</span>
  </div>
  <div class="timeline-scroll">
    @include('partials.activity-timeline', [
      'entries' => $custodyHistory,
      'registeredAt' => $pdl->admission_date,
      'registeredLabel' => 'Admitted to the facility',
      'registeredDateOnly' => true,
    ])
  </div>
</div>

{{-- ================= RESTRICTIONS ================= --}}
<div class="panel" style="margin-bottom:20px;" id="restrictions">
  <div class="panel-header">
    <h2>Restrictions <span class="count-chip">{{ $pdl->restrictions->count() }}</span></h2>
    @if ($activeRestrictions->isNotEmpty())
      <span class="badge restricted"><span class="dot"></span>Visitation blocked while a restriction is active</span>
    @endif
  </div>

  @if ($pdl->restrictions->isEmpty())
    <p class="empty-note">No restrictions on file.</p>
  @else
    <div class="rec-list">
      @foreach ($pdl->restrictions->sortByDesc(fn ($r) => $r->isActive()) as $restriction)
        <div class="rec">
          <div class="rec-head">
            <span class="rec-title">{{ $restrictionLabel($restriction->restriction_type) }}</span>
            <span class="badge {{ $restriction->isActive() ? 'restricted' : 'released' }}">
              <span class="dot"></span>{{ $restriction->isActive() ? 'Active' : 'Lifted' }}
            </span>
          </div>
          <div class="rec-meta">
            {{ $restriction->start_date->format('M j, Y') }} – {{ optional($restriction->end_date)->format('M j, Y') ?? 'until lifted' }}
          </div>
          <div class="rec-text">{{ $restriction->reason }}</div>
          @if ($restriction->disciplinaryRecord)
            <div class="rec-meta">From incident: <a href="#disciplinary-records" class="row-action">{{ $restriction->disciplinaryRecord->shortLabel() }}</a></div>
          @endif
          @if ($restriction->isActive())
            <form class="rec-actions" method="POST" action="{{ route('pdl.restrictions.lift', [$pdl->pdl_id, $restriction->restriction_id]) }}"
                  data-confirm="Lift this {{ str_replace('_', ' ', $restriction->restriction_type) }} restriction? It ends today and visitation may resume."
                  data-confirm-title="Lift restriction" data-confirm-ok="Lift">
              @csrf
              @method('PATCH')
              <button type="submit" class="row-action">Lift restriction</button>
            </form>
          @endif
        </div>
      @endforeach
    </div>
  @endif

  @if ($pdl->custody_status === 'active')
    <details class="add-record" {{ $openRestriction ? 'open' : '' }}>
      <summary class="btn btn-outline-neutral">+ Add Restriction</summary>
      <form method="POST" action="{{ route('pdl.restrictions.store', $pdl->pdl_id) }}" style="margin-top:14px;"
            data-confirm="Add this restriction to {{ $pdl->full_name }}? Visitation will be blocked while it is active." data-confirm-title="Add restriction" data-confirm-ok="Add restriction" data-confirm-danger>
        @csrf
        <div class="row cols-3">
          <div class="field-m">
            <label>Type</label>
            <select name="restriction_type" required>
              @foreach (\App\Models\PdlRestriction::TYPES as $type)
                <option value="{{ $type }}" {{ old('restriction_type') === $type ? 'selected' : '' }}>{{ $restrictionLabel($type) }}</option>
              @endforeach
            </select>
          </div>
          <div class="field-m"><label>Start Date</label><input type="date" name="start_date" value="{{ old('start_date', now()->toDateString()) }}" required min="{{ $pdl->admission_date->toDateString() }}"></div>
          <div class="field-m"><label>End Date (optional)</label><input type="date" name="end_date" value="{{ old('end_date') }}" min="{{ now()->toDateString() }}"></div>
        </div>
        <div class="row">
          <div class="field-m"><label>Reason</label><input type="text" name="reason" value="{{ old('reason') }}" required minlength="5" maxlength="255"></div>
        </div>
        @if ($pdl->disciplinaryRecords->isNotEmpty())
          <div class="row">
            <div class="field-m">
              <label>Related incident (optional)</label>
              <select name="disciplinary_record_id">
                <option value="">None — not caused by an incident</option>
                @foreach ($pdl->disciplinaryRecords as $incident)
                  <option value="{{ $incident->disciplinary_record_id }}" {{ (string) old('disciplinary_record_id') === (string) $incident->disciplinary_record_id ? 'selected' : '' }}>{{ $incident->shortLabel() }}</option>
                @endforeach
              </select>
              <p class="field-hint">Pick the disciplinary record that led to this restriction. That record will then show "Led to restriction".</p>
              @error('disciplinary_record_id')<p class="field-hint" style="color:var(--red-text);">{{ $message }}</p>@enderror
            </div>
          </div>
        @endif
        <button class="btn btn-blue" type="submit">Add Restriction</button>
      </form>
    </details>
  @endif
</div>

{{-- ================= LEGAL RECORDS ================= --}}
<div class="panel" style="margin-bottom:20px;" id="legal-records">
  <div class="panel-header"><h2>Legal Records <span class="count-chip">{{ $pdl->legalRecords->count() }}</span></h2></div>

  @if ($pdl->legalRecords->isEmpty())
    <p class="empty-note">No legal records on file yet.</p>
  @else
    <div class="rec-list">
      @foreach ($pdl->legalRecords as $record)
        @php
          $caseBadge = match (true) {
            $record->isConvicted() => 'rejected',
            $record->isClosed() => 'released',
            default => 'transferred',
          };
          $next = $record->nextHearing();
          $past = $record->pastHearings();
        @endphp
        <div class="rec">
          <div class="rec-head">
            <span class="rec-title">{{ $record->offense }}@if ($record->isDrugRelated()) <span class="pill drug-pill">RA 9165</span>@endif</span>
            @if ($record->case_status)
              <span class="badge {{ $caseBadge }}"><span class="dot"></span>{{ $record->case_status }}</span>
            @endif
          </div>
          <div class="rec-meta">
            Case <strong>{{ $record->case_number }}</strong>
            · {{ $record->court ?: 'Court not recorded' }}
            · {{ $record->date_filed ? 'Filed ' . $record->date_filed->format('M j, Y') : 'Recorded ' . $record->created_at->format('M j, Y') }}
          </div>
          @if ($record->isConvicted())
            <div class="rec-meta">
              Convicted {{ $record->convicted_on?->format('M j, Y') }} · {{ $record->sentenceLabel() ?? 'no sentence entered' }}
              @if ($release = $record->estimatedRelease())
                · <span title="Admission date plus the sentence, before good conduct time allowance and other credits">Est. release {{ $release->format('M j, Y') }}</span>
              @endif
            </div>
          @endif
          <div class="rec-meta">
            @if ($next)
              Next hearing: <strong>{{ $next->hearing_at->format('M j, Y H:i') }}</strong>@if ($next->notes) · {{ $next->notes }}@endif
            @else
              {{ $record->isClosed() ? 'Case closed' : 'No hearing set' }}
            @endif
          </div>
          @if ($record->remarks)
            <div class="rec-text">{{ $record->remarks }}</div>
          @endif
          @if ($past->isNotEmpty())
            <details class="past-hearings">
              <summary>{{ $past->count() }} past {{ $past->count() === 1 ? 'hearing' : 'hearings' }}</summary>
              <ul>
                @foreach ($past as $hearing)
                  <li>{{ $hearing->hearing_at->format('M j, Y H:i') }}@if ($hearing->notes) · {{ $hearing->notes }}@endif</li>
                @endforeach
              </ul>
            </details>
          @endif
          <div class="rec-actions">
            <button type="button" class="row-action" data-open-record="record-update-{{ $record->legal_record_id }}">Update case</button>
          </div>
        </div>
      @endforeach
    </div>
  @endif

  {{-- One Update window per record (outside the scrolling table so nothing is clipped). --}}
  @foreach ($pdl->legalRecords as $record)
    @php $editingThis = (string) old('editing_record') === (string) $record->legal_record_id; @endphp
    <dialog class="record-update" id="record-update-{{ $record->legal_record_id }}" aria-label="Update legal record" @if ($editingThis) data-open-on-load @endif>
      <header class="record-update-head">
        <div>
          <strong>Update case {{ $record->case_number }}</strong>
          <div class="muted-cell">{{ $record->offense }}{{ $record->court ? ' · ' . $record->court : '' }}</div>
        </div>
        <button type="button" class="elig-docs-close" data-close-record aria-label="Close">×</button>
      </header>
      <form method="POST" action="{{ route('pdl.legal-records.update', [$pdl->pdl_id, $record->legal_record_id]) }}"
            data-confirm="Save the changes to case {{ $record->case_number }}?" data-confirm-title="Update legal record" data-confirm-ok="Save">
        @csrf
        @method('PATCH')
        <input type="hidden" name="editing_record" value="{{ $record->legal_record_id }}">
        @include('pdl.partials.case-status-fields', ['record' => $record, 'useOld' => $editingThis])
        <div class="field-m" style="margin-bottom:14px;">
          <label>Remarks (optional)</label>
          <input type="text" name="remarks" value="{{ $editingThis ? old('remarks', $record->remarks) : $record->remarks }}" maxlength="2000">
        </div>
        <div style="display:flex;gap:10px;">
          <button class="btn btn-blue" type="submit">Save</button>
          <button class="btn btn-outline-neutral" type="button" data-close-record>Cancel</button>
        </div>
      </form>
    </dialog>
  @endforeach
  <script>
    (function () {
      document.addEventListener('click', function (e) {
        var open = e.target.closest('[data-open-record]');
        if (open) { document.getElementById(open.dataset.openRecord)?.showModal(); return; }
        if (e.target.closest('[data-close-record]') || e.target.matches('dialog.record-update')) {
          e.target.closest('dialog.record-update')?.close();
        }
      });
      document.querySelectorAll('dialog.record-update[data-open-on-load]').forEach(function (d) { d.showModal(); });
    })();
  </script>

  <details class="add-record" {{ $openLegal ? 'open' : '' }}>
    <summary class="btn btn-outline-neutral">+ Add Legal Record</summary>
    <form method="POST" action="{{ route('pdl.legal-records.store', $pdl->pdl_id) }}" style="margin-top:14px;"
          data-confirm="Add this legal record to {{ $pdl->full_name }}'s file?" data-confirm-title="Add legal record" data-confirm-ok="Add">
      @csrf
      @include('pdl.partials.case-fields')
      @include('pdl.partials.case-status-fields', ['useOld' => ! old('editing_record')])
      <div class="row">
        <div class="field-m"><label>Remarks (optional)</label><input type="text" name="remarks" value="{{ old('editing_record') ? '' : old('remarks') }}" maxlength="2000"></div>
      </div>
      <button class="btn btn-blue" type="submit">Add Legal Record</button>
    </form>
  </details>
</div>

{{-- ================= DISCIPLINARY RECORDS ================= --}}
<div class="panel" id="disciplinary-records">
  <div class="panel-header"><h2>Disciplinary Records <span class="count-chip">{{ $pdl->disciplinaryRecords->count() }}</span></h2></div>

  @if ($pdl->disciplinaryRecords->isEmpty())
    <p class="empty-note">No disciplinary records on file.</p>
  @else
    <div class="rec-list">
      @foreach ($pdl->disciplinaryRecords as $record)
        <div class="rec">
          <div class="rec-head">
            <span class="rec-title">{{ $record->incident_date->format('M j, Y') }}</span>
            @if ($record->ledToRestriction())
              <a href="#restrictions" class="badge restricted" style="text-decoration:none;"><span class="dot"></span>Led to restriction</a>
            @else
              <span class="badge released"><span class="dot"></span>No restriction</span>
            @endif
          </div>
          <div class="rec-text">{{ $record->description }}</div>
          <div class="rec-meta">Action taken: {{ $record->action_taken ?: '—' }}</div>
        </div>
      @endforeach
    </div>
  @endif

  <details class="add-record" {{ $openDisciplinary ? 'open' : '' }}>
    <summary class="btn btn-outline-neutral">+ Add Disciplinary Record</summary>
    <form method="POST" action="{{ route('pdl.disciplinary-records.store', $pdl->pdl_id) }}" style="margin-top:14px;"
          data-confirm="Add this disciplinary record to {{ $pdl->full_name }}'s file? Records cannot be deleted afterwards." data-confirm-title="Add disciplinary record" data-confirm-ok="Add">
      @csrf
      <div class="row cols-2">
        <div class="field-m"><label>Incident Date</label><input type="date" name="incident_date" value="{{ old('incident_date') }}" required min="{{ $pdl->admission_date->toDateString() }}" max="{{ now()->toDateString() }}"></div>
        <div class="field-m"><label>Action Taken</label><input type="text" name="action_taken" value="{{ old('action_taken') }}" maxlength="255"></div>
      </div>
      <div class="row">
        <div class="field-m"><label>Description</label><textarea name="description" required minlength="10" maxlength="2000">{{ old('description') }}</textarea></div>
      </div>
      <p class="field-hint" style="margin:0 0 14px;">To block visits because of this incident, add a restriction under <a href="#restrictions">Restrictions</a> and pick this incident as the related incident.</p>
      <button class="btn btn-blue" type="submit">Add Disciplinary Record</button>
    </form>
  </details>
</div>

<script>
  // Edit Profile: show the edit panel under the summary, and hide it again on Cancel.
  (function () {
    var panel = document.querySelector('[data-profile-panel]');
    var form = document.querySelector('[data-profile-edit]');
    var openBtn = document.querySelector('[data-edit-open]');

    function setEditing(on) {
      panel.hidden = !on;
      openBtn.hidden = on;
      if (on) {
        document.getElementById('profile').scrollIntoView({ behavior: 'smooth', block: 'start' });
        var first = form.querySelector('input[name="first_name"]');
        if (first) first.focus({ preventScroll: true });
      }
    }

    openBtn.addEventListener('click', function () { setEditing(true); });
    document.querySelector('[data-edit-cancel]').addEventListener('click', function () {
      form.reset(); // throw away unsaved edits
      form.querySelectorAll('input[type="file"]').forEach(function (f) { f.dispatchEvent(new Event('change')); });
      setEditing(false);
    });
  })();
</script>
@endsection
