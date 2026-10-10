@extends('layouts.app')
@section('title', $pdl->full_name . ' — CustodiCore')

@php
  $statusLabels = ['active' => 'Active', 'released' => 'Released', 'transferred' => 'Transferred', 'deceased' => 'Deceased'];
  $classificationLabels = ['drug_related' => 'Drug-related', 'non_drug_related' => 'Non-drug-related'];
  $restrictionLabel = fn ($type) => \Illuminate\Support\Str::of($type)->replace('_', ' ')->title();
  $activeRestrictions = $pdl->restrictions->filter->isActive();
  $nameParts = $pdl->nameParts();

  // Open the edit form again after a failed save (wrong password, invalid
  // field), and the matching "Add …" form after its own validation error.
  $profileFields = ['first_name', 'middle_name', 'last_name', 'alias', 'gender', 'date_of_birth', 'classification', 'admission_date', 'cell_block', 'custody_status', 'photo', 'current_password'];
  $editing = $errors->hasAny($profileFields);
  $openLegal = $errors->hasAny(['case_number', 'offense', 'court', 'case_status', 'remarks']);
  $openDisciplinary = $errors->hasAny(['incident_date', 'description', 'action_taken']);
  $openRestriction = $errors->hasAny(['restriction_type', 'start_date', 'end_date', 'reason']);
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

  <dl class="pdl-facts">
    <div><dt>Cell/Block</dt><dd>{{ $pdl->cell_block ?? 'Not assigned' }}</dd></div>
    <div><dt>Classification</dt><dd>{{ $classificationLabels[$pdl->classification] ?? '—' }}</dd></div>
    <div><dt>Visiting Days</dt><dd>{{ $visitingDays ?: 'No schedule set' }}</dd></div>
    <div>
      <dt>Age</dt>
      <dd>{{ $pdl->date_of_birth ? $pdl->date_of_birth->age . ' years old' : '—' }}</dd>
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
  </dl>
</div>

{{-- ================= PROFILE DETAILS (view / edit) ================= --}}
<div class="panel" style="margin-bottom:20px;" id="profile">
  <div class="panel-header">
    <h2>Profile Details</h2>
    <span class="muted-cell" data-edit-hint {{ $editing ? '' : 'hidden' }}>Editing. Changes are saved after you confirm with your password.</span>
  </div>

  {{-- Read-only view --}}
  <dl class="pdl-details" data-profile-view {{ $editing ? 'hidden' : '' }}>
    <div><dt>First Name</dt><dd>{{ $nameParts['first'] ?: '—' }}</dd></div>
    <div><dt>Middle Name</dt><dd>{{ $nameParts['middle'] ?: '—' }}</dd></div>
    <div><dt>Last Name</dt><dd>{{ $nameParts['last'] ?: '—' }}</dd></div>
    <div><dt>Alias</dt><dd>{{ $pdl->alias ?: '—' }}</dd></div>
    <div><dt>Gender</dt><dd>{{ ucfirst($pdl->gender ?? '—') }}</dd></div>
    <div><dt>Date of Birth</dt><dd>{{ $pdl->date_of_birth?->format('M j, Y') ?? '—' }}</dd></div>
    <div><dt>Classification</dt><dd>{{ $classificationLabels[$pdl->classification] ?? '—' }}</dd></div>
    <div><dt>Admission Date</dt><dd>{{ $pdl->admission_date?->format('M j, Y') ?? '—' }}</dd></div>
    <div><dt>Cell/Block</dt><dd>{{ $pdl->cell_block ?? 'Not assigned' }}</dd></div>
    <div><dt>Custody Status</dt><dd>{{ $statusLabels[$pdl->custody_status] ?? ucfirst($pdl->custody_status) }}</dd></div>
  </dl>
  @if ($pdl->first_name === null && $pdl->last_name === null)
    <p class="field-hint" data-profile-view {{ $editing ? 'hidden' : '' }}>Only a full name is on file for this record. Open <strong>Edit Profile</strong> to confirm the first, middle and last name.</p>
  @endif

  {{-- Edit form. Final step on save: the officer re-enters their own password (data-password-confirm → partials/password-confirm). --}}
  <form method="POST" action="{{ route('pdl.update', $pdl->pdl_id) }}" enctype="multipart/form-data" data-profile-edit {{ $editing ? '' : 'hidden' }}
        data-password-confirm="Save changes to {{ $pdl->full_name }}'s profile? Enter your password to confirm.">
    @csrf
    @method('PUT')

    <p class="section-title"><span class="icon">📷</span> Photo</p>
    <div class="row">
      @include('pdl.partials.photo-field', ['pdl' => $pdl])
    </div>

    <p class="section-title"><span class="icon">👤</span> Personal Details</p>
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
      <div class="field-m"><label>Date of Birth</label><input type="date" name="date_of_birth" value="{{ old('date_of_birth', optional($pdl->date_of_birth)->format('Y-m-d')) }}" required min="1900-01-02" max="{{ now()->subDay()->toDateString() }}"></div>
    </div>

    <p class="section-title"><span class="icon">🏢</span> Custody Details</p>
    <div class="row cols-3">
      <div class="field-m">
        <label>Classification</label>
        {{-- Not free text — it drives the visiting-day rule
             (drug_related -> Thu/Sat, non_drug_related -> Fri/Sun). --}}
        <select name="classification">
          <option value="drug_related" {{ old('classification', $pdl->classification) === 'drug_related' ? 'selected' : '' }}>Drug-related</option>
          <option value="non_drug_related" {{ old('classification', $pdl->classification) === 'non_drug_related' ? 'selected' : '' }}>Non-drug-related</option>
        </select>
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
      @include('pdl.partials.cell-block-select', ['selected' => old('cell_block', $pdl->cell_block), 'current' => $pdl->cell_block, 'required' => false])
    </div>

    <div class="form-actions">
      <button class="btn btn-blue" type="submit">Save Profile Changes</button>
      <button class="btn btn-outline-neutral" type="button" data-edit-cancel>Cancel</button>
    </div>
  </form>
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
    <table>
      <thead><tr><th>Type</th><th>Status</th><th>Start</th><th>End</th><th>Reason</th><th></th></tr></thead>
      <tbody>
        @foreach ($pdl->restrictions->sortByDesc(fn ($r) => $r->isActive()) as $restriction)
        <tr>
          <td><strong>{{ $restrictionLabel($restriction->restriction_type) }}</strong></td>
          <td>
            <span class="badge {{ $restriction->isActive() ? 'restricted' : 'released' }}">
              <span class="dot"></span>{{ $restriction->isActive() ? 'Active' : 'Lifted' }}
            </span>
          </td>
          <td class="muted-cell">{{ $restriction->start_date->format('M d, Y') }}</td>
          <td class="muted-cell">{{ optional($restriction->end_date)->format('M d, Y') ?? 'Until lifted' }}</td>
          <td class="muted-cell">{{ $restriction->reason }}</td>
          <td style="text-align:right;">
            @if ($restriction->isActive())
              <form method="POST" action="{{ route('pdl.restrictions.lift', [$pdl->pdl_id, $restriction->restriction_id]) }}"
                    data-confirm="Lift this {{ str_replace('_', ' ', $restriction->restriction_type) }} restriction? It ends today and visitation may resume."
                    data-confirm-title="Lift restriction" data-confirm-ok="Lift">
                @csrf
                @method('PATCH')
                <button type="submit" class="row-action">Lift</button>
              </form>
            @endif
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
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
        <button class="btn btn-blue" type="submit">Add Restriction</button>
      </form>
    </details>
  @endif
</div>

{{-- ================= LEGAL RECORDS ================= --}}
<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Legal Records <span class="count-chip">{{ $pdl->legalRecords->count() }}</span></h2></div>

  @if ($pdl->legalRecords->isEmpty())
    <p class="empty-note">No legal records on file yet.</p>
  @else
    <table>
      <thead><tr><th>Case Number</th><th>Offense</th><th>Court</th><th>Status</th><th>Remarks</th><th>Recorded</th></tr></thead>
      <tbody>
        @foreach ($pdl->legalRecords as $record)
        <tr>
          <td><strong>{{ $record->case_number }}</strong></td>
          <td>{{ $record->offense }}</td>
          <td class="muted-cell">{{ $record->court ?? '—' }}</td>
          <td class="muted-cell">{{ $record->case_status ?? '—' }}</td>
          <td class="muted-cell">{{ $record->remarks ?? '—' }}</td>
          <td class="muted-cell">{{ $record->created_at->format('M d, Y') }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  <details class="add-record" {{ $openLegal ? 'open' : '' }}>
    <summary class="btn btn-outline-neutral">+ Add Legal Record</summary>
    <form method="POST" action="{{ route('pdl.legal-records.store', $pdl->pdl_id) }}" style="margin-top:14px;"
          data-confirm="Add this legal record to {{ $pdl->full_name }}'s file?" data-confirm-title="Add legal record" data-confirm-ok="Add">
      @csrf
      <div class="row cols-3">
        <div class="field-m"><label>Case Number</label><input type="text" name="case_number" value="{{ old('case_number') }}" required maxlength="50" pattern="[A-Za-z0-9][A-Za-z0-9 .\/\-]*" title="Letters, numbers, spaces, dots, slashes and dashes only"></div>
        <div class="field-m"><label>Offense</label><input type="text" name="offense" value="{{ old('offense') }}" required minlength="3" maxlength="255"></div>
        <div class="field-m"><label>Court</label><input type="text" name="court" value="{{ old('court') }}" maxlength="150"></div>
      </div>
      <div class="row cols-2">
        <div class="field-m"><label>Case Status</label><input type="text" name="case_status" value="{{ old('case_status') }}" maxlength="100" placeholder="e.g. Pending, Dismissed, Convicted"></div>
        <div class="field-m"><label>Remarks</label><input type="text" name="remarks" value="{{ old('remarks') }}" maxlength="2000"></div>
      </div>
      <button class="btn btn-blue" type="submit">Add Legal Record</button>
    </form>
  </details>
</div>

{{-- ================= DISCIPLINARY RECORDS ================= --}}
<div class="panel">
  <div class="panel-header"><h2>Disciplinary Records <span class="count-chip">{{ $pdl->disciplinaryRecords->count() }}</span></h2></div>

  @if ($pdl->disciplinaryRecords->isEmpty())
    <p class="empty-note">No disciplinary records on file.</p>
  @else
    <table>
      <thead><tr><th>Incident Date</th><th>Description</th><th>Action Taken</th><th>Triggers Restriction</th></tr></thead>
      <tbody>
        @foreach ($pdl->disciplinaryRecords as $record)
        <tr>
          <td class="muted-cell">{{ $record->incident_date->format('M d, Y') }}</td>
          <td>{{ $record->description }}</td>
          <td class="muted-cell">{{ $record->action_taken ?? '—' }}</td>
          <td>{{ $record->triggers_restriction ? 'Yes' : 'No' }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
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
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:14px;">
        <input type="checkbox" name="triggers_restriction" value="1" {{ old('triggers_restriction') ? 'checked' : '' }}> This incident should trigger a restriction
      </label>
      <button class="btn btn-blue" type="submit">Add Disciplinary Record</button>
    </form>
  </details>
</div>

<script>
  // Edit Profile: swap the read-only details for the form, and back.
  (function () {
    var form = document.querySelector('[data-profile-edit]');
    var views = document.querySelectorAll('[data-profile-view]');
    var openBtn = document.querySelector('[data-edit-open]');
    var hint = document.querySelector('[data-edit-hint]');

    function setEditing(on) {
      form.hidden = !on;
      views.forEach(function (v) { v.hidden = on; });
      openBtn.hidden = on;
      hint.hidden = !on;
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
