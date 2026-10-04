@extends('layouts.app')
@section('title', $pdl->full_name . ' — CustodiCore')

@section('content')
<div class="topbar">
  <h1>{{ $pdl->full_name }} <span class="muted-cell">({{ $pdl->pdl_number }})</span></h1>
  <div class="right">
    <a class="btn btn-outline-neutral" href="{{ route('pdl.index') }}">← Back to PDL Management</a>
  </div>
</div>

<div class="panel" style="margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;">
  <div>
    <div class="stat-label">Visitation Eligibility</div>
    <span class="badge {{ $eligibilityStatus['class'] }}" style="margin-top:6px;font-size:13px;">
      <span class="dot"></span>{{ $eligibilityStatus['label'] }}
    </span>
  </div>
  <a class="row-action" href="{{ route('visitation-tracking.show', $pdl->pdl_id) }}">View Visit History →</a>
</div>

{{-- ================= CORE PROFILE ================= --}}
<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Profile</h2></div>
  {{-- Final step on save: the officer re-enters their own password (data-password-confirm → partials/password-confirm). --}}
  <form method="POST" action="{{ route('pdl.update', $pdl->pdl_id) }}"
        data-password-confirm="Save changes to {{ $pdl->full_name }}'s profile? Enter your password to confirm.">
    @csrf
    @method('PUT')
    <div class="row cols-3">
      <div class="field-m"><label>Full Name</label><input type="text" name="full_name" value="{{ old('full_name', $pdl->full_name) }}" required></div>
      <div class="field-m"><label>Alias</label><input type="text" name="alias" value="{{ old('alias', $pdl->alias) }}"></div>
      <div class="field-m">
        <label>Gender</label>
        <select name="gender">
          {{-- old() first so a rejected password doesn't throw away the officer's edits --}}
          <option value="male" {{ old('gender', $pdl->gender) === 'male' ? 'selected' : '' }}>Male</option>
          <option value="female" {{ old('gender', $pdl->gender) === 'female' ? 'selected' : '' }}>Female</option>
          <option value="other" {{ old('gender', $pdl->gender) === 'other' ? 'selected' : '' }}>Other</option>
        </select>
      </div>
    </div>
    <div class="row cols-3">
      <div class="field-m"><label>Date of Birth</label><input type="date" name="date_of_birth" value="{{ old('date_of_birth', optional($pdl->date_of_birth)->format('Y-m-d')) }}" required></div>
      <div class="field-m">
        <label>Classification</label>
        {{-- CONFIRMED: this isn't free text — it drives the visiting-day rule
             (drug_related -> Thu/Sat, non_drug_related -> Fri/Sun) per the
             migration's own comment. --}}
        <select name="classification">
          <option value="drug_related" {{ old('classification', $pdl->classification) === 'drug_related' ? 'selected' : '' }}>Drug-related</option>
          <option value="non_drug_related" {{ old('classification', $pdl->classification) === 'non_drug_related' ? 'selected' : '' }}>Non-drug-related</option>
        </select>
      </div>
      <div class="field-m"><label>Cell/Block</label><input type="text" name="cell_block" value="{{ old('cell_block', $pdl->cell_block) }}" placeholder="e.g. Block A - 102"></div>
    </div>
    <div class="row cols-2">
      <div class="field-m"><label>Admission Date</label><input type="date" name="admission_date" value="{{ old('admission_date', optional($pdl->admission_date)->format('Y-m-d')) }}" required></div>
      <div class="field-m">
        <label>Custody Status</label>
        <select name="custody_status">
          @foreach (['active' => 'Active', 'released' => 'Released', 'transferred' => 'Transferred', 'deceased' => 'Deceased'] as $value => $label)
            <option value="{{ $value }}" {{ old('custody_status', $pdl->custody_status) === $value ? 'selected' : '' }}>{{ $label }}</option>
          @endforeach
        </select>
      </div>
    </div>
    <button class="btn btn-blue" type="submit">Save Profile Changes</button>
  </form>
</div>

{{-- ================= LEGAL RECORDS (one-to-many) ================= --}}
<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Legal Records</h2></div>

  @if ($pdl->legalRecords->isEmpty())
    <p class="empty-note">No legal records on file yet.</p>
  @else
    <table>
      <thead><tr><th>Case Number</th><th>Offense</th><th>Court</th><th>Status</th><th>Remarks</th><th>Recorded</th></tr></thead>
      <tbody>
        @foreach ($pdl->legalRecords as $record)
        <tr>
          <td>{{ $record->case_number }}</td>
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

  <details style="margin-top:14px;">
    <summary class="row-action" style="cursor:pointer;">+ Add Legal Record</summary>
    <form method="POST" action="{{ route('pdl.legal-records.store', $pdl->pdl_id) }}" style="margin-top:12px;">
      @csrf
      <div class="row cols-3">
        <div class="field-m"><label>Case Number</label><input type="text" name="case_number" required></div>
        <div class="field-m"><label>Offense</label><input type="text" name="offense" required></div>
        <div class="field-m"><label>Court</label><input type="text" name="court"></div>
      </div>
      <div class="row cols-2">
        <div class="field-m"><label>Case Status</label><input type="text" name="case_status" placeholder="e.g. Pending, Dismissed, Convicted"></div>
        <div class="field-m"><label>Remarks</label><input type="text" name="remarks"></div>
      </div>
      <button class="btn btn-blue" type="submit">Add Legal Record</button>
    </form>
  </details>
</div>

{{-- ================= DISCIPLINARY RECORDS (one-to-many) ================= --}}
<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Disciplinary Records</h2></div>

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
          <td>{{ $record->triggers_restriction ? '⚠ Yes' : 'No' }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  <details style="margin-top:14px;">
    <summary class="row-action" style="cursor:pointer;">+ Add Disciplinary Record</summary>
    <form method="POST" action="{{ route('pdl.disciplinary-records.store', $pdl->pdl_id) }}" style="margin-top:12px;">
      @csrf
      <div class="row cols-2">
        <div class="field-m"><label>Incident Date</label><input type="date" name="incident_date" required></div>
        <div class="field-m"><label>Action Taken</label><input type="text" name="action_taken"></div>
      </div>
      <div class="row">
        <div class="field-m"><label>Description</label><textarea name="description" required></textarea></div>
      </div>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:14px;">
        <input type="checkbox" name="triggers_restriction" value="1"> This incident should trigger a restriction
      </label>
      <button class="btn btn-blue" type="submit">Add Disciplinary Record</button>
    </form>
  </details>
</div>

{{-- ================= RESTRICTIONS (one-to-many) ================= --}}
<div class="panel">
  <div class="panel-header"><h2>Restrictions</h2></div>

  @if ($pdl->restrictions->isEmpty())
    <p class="empty-note">No restrictions on file.</p>
  @else
    <table>
      <thead><tr><th>Type</th><th>Status</th><th>Start</th><th>End</th><th>Reason</th><th></th></tr></thead>
      <tbody>
        @foreach ($pdl->restrictions as $restriction)
        <tr>
          <td class="muted-cell">{{ ucfirst($restriction->restriction_type) }}</td>
          <td>
            <span class="badge {{ $restriction->isActive() ? 'transferred' : 'released' }}">
              <span class="dot"></span>{{ strtoupper($restriction->status) }}
            </span>
          </td>
          <td class="muted-cell">{{ $restriction->start_date->format('M d, Y') }}</td>
          <td class="muted-cell">{{ optional($restriction->end_date)->format('M d, Y') ?? '—' }}</td>
          <td class="muted-cell">{{ $restriction->reason }}</td>
          <td>
            @if ($restriction->isActive())
              <form method="POST" action="{{ route('pdl.restrictions.lift', [$pdl->pdl_id, $restriction->restriction_id]) }}">
                @csrf
                <button type="submit" class="row-action">Lift →</button>
              </form>
            @endif
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  <details style="margin-top:14px;">
    <summary class="row-action" style="cursor:pointer;">+ Add Restriction</summary>
    <form method="POST" action="{{ route('pdl.restrictions.store', $pdl->pdl_id) }}" style="margin-top:12px;">
      @csrf
      <div class="row cols-3">
        <div class="field-m">
          <label>Type</label>
          {{-- CONFIRMED full enum from the actual migration. --}}
          <select name="restriction_type" required>
            <option value="disciplinary">Disciplinary</option>
            <option value="quarantine">Quarantine</option>
            <option value="court_order">Court Order</option>
            <option value="transfer_pending">Transfer Pending</option>
            <option value="legal_prohibition">Legal Prohibition</option>
            <option value="victim_related">Victim-related</option>
            <option value="restraining_order">Restraining Order</option>
          </select>
        </div>
        <div class="field-m"><label>Start Date</label><input type="date" name="start_date" required></div>
        <div class="field-m"><label>End Date (optional)</label><input type="date" name="end_date"></div>
      </div>
      <div class="row">
        <div class="field-m"><label>Reason</label><input type="text" name="reason" required></div>
      </div>
      <button class="btn btn-blue" type="submit">Add Restriction</button>
    </form>
  </details>
</div>
@endsection