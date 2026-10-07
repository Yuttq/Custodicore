@extends('layouts.app')
@section('title', $visitor->full_name . ' — CustodiCore')

@section('content')
<div class="topbar">
  <h1>{{ $visitor->full_name }}</h1>
  <div class="right">
    <a class="btn btn-outline-neutral" href="{{ route('visitor.index') }}">Back to Visitor Management</a>
  </div>
</div>

<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Profile</h2></div>
  <div class="row cols-3" style="margin-bottom:0;">
    <div><div class="stat-label">Date of Birth</div><div>{{ $visitor->date_of_birth->format('M d, Y') }}</div></div>
    <div><div class="stat-label">Gender</div><div>{{ $visitor->gender ? ucfirst($visitor->gender) : 'Prefer not to say' }}</div></div>
    <div><div class="stat-label">Contact Number</div><div>{{ $visitor->contact_number }}</div></div>
  </div>
  <div class="row cols-2" style="margin-top:16px;margin-bottom:0;">
    <div><div class="stat-label">Address</div><div>{{ $visitor->address ?? '—' }}</div></div>
    <div><div class="stat-label">Emergency Contact</div><div>{{ $visitor->emergency_contact_name ?? '—' }} {{ $visitor->emergency_contact_number ? '('.$visitor->emergency_contact_number.')' : '' }}</div></div>
  </div>
  @if ($visitor->relationship_hint)
  <div class="row" style="margin-top:16px;margin-bottom:0;">
    <div><div class="stat-label">Relationship Hint (from registration)</div><div>{{ $visitor->relationship_hint }}</div></div>
  </div>
  @endif
</div>

{{-- ================= ACCOUNT REVIEW ================= --}}
<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Account Review</h2></div>
  <div class="row cols-3" style="margin-bottom:0;">
    <div>
      <div class="stat-label">Review Status</div>
      <span class="badge {{ $visitor->verification_status === 'verified' ? 'active' : ($visitor->verification_status === 'rejected' ? 'rejected' : 'transferred') }}">
        <span class="dot"></span>{{ $visitor->verification_status === 'verified' ? 'APPROVED' : strtoupper($visitor->verification_status) }}
      </span>
    </div>
    <div>
      <div class="stat-label">Email</div>
      <div>{{ $visitor->account?->email ?? '—' }} {{ $visitor->account?->email_verified_at ? '(verified)' : '(not verified yet)' }}</div>
    </div>
    <div>
      <div class="stat-label">Reviewed</div>
      <div>{{ $visitor->verified_at ? $visitor->verified_at->format('M d, Y g:i A') : '—' }}</div>
    </div>
  </div>
  @if ($visitor->verification_status === 'rejected' && $visitor->rejection_reason)
    <div class="row" style="margin-top:16px;margin-bottom:0;">
      <div><div class="stat-label">Reason shown to the visitor</div><div>{{ $visitor->rejection_reason }}</div></div>
    </div>
  @endif

  <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;margin-top:16px;">
    @if ($visitor->verification_status !== 'verified')
      <form method="POST" action="{{ route('visitor.approve', $visitor->visitor_id) }}">
        @csrf
        <button class="btn btn-blue" type="submit">Approve Visitor</button>
      </form>
    @endif
    @if ($visitor->verification_status !== 'rejected')
      <details>
        <summary class="row-action" style="cursor:pointer;color:var(--red);">Reject Visitor</summary>
        <form method="POST" action="{{ route('visitor.reject', $visitor->visitor_id) }}" style="margin-top:12px;">
          @csrf
          <div class="row">
            <div class="field-m">
              <label>Reason (shown to the visitor)</label>
              <textarea name="rejection_reason" maxlength="500" placeholder="e.g. The uploaded ID photo is unreadable. Please upload a clearer copy."></textarea>
            </div>
          </div>
          <button class="btn btn-outline-neutral" type="submit" style="color:var(--red);">Confirm Rejection</button>
        </form>
      </details>
    @endif
  </div>
</div>

{{-- ================= IDENTITY VERIFICATION ================= --}}
<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Identity Documents</h2></div>

  @if ($visitor->idDocuments->isEmpty())
    <p class="empty-note">No ID documents uploaded yet.</p>
  @else
    <table>
      <thead><tr><th>Type</th><th>ID Number</th><th>Status</th><th>Verified By</th><th></th></tr></thead>
      <tbody>
        @foreach ($visitor->idDocuments as $doc)
        <tr>
          <td>{{ $doc->typeLabel() }}</td>
          <td class="muted-cell">{{ $doc->id_number }}</td>
          <td>
            <span class="badge {{ $doc->verification_status === 'verified' ? 'active' : ($doc->verification_status === 'rejected' ? 'rejected' : 'transferred') }}">
              <span class="dot"></span>{{ strtoupper($doc->verification_status) }}
            </span>
          </td>
          <td class="muted-cell">{{ $doc->verifiedBy?->full_name ?? '—' }}</td>
          <td>
            <div style="display:flex;gap:8px;">
              @if ($doc->verification_status !== 'verified')
                <form method="POST" action="{{ route('visitor.ids.verify', [$visitor->visitor_id, $doc->visitor_id_doc_id]) }}">
                  @csrf
                  <button type="submit" class="row-action" style="color:var(--green);">Verify</button>
                </form>
              @endif
              @if ($doc->verification_status !== 'rejected')
                <form method="POST" action="{{ route('visitor.ids.reject', [$visitor->visitor_id, $doc->visitor_id_doc_id]) }}">
                  @csrf
                  <button type="submit" class="row-action" style="color:var(--red);">Reject</button>
                </form>
              @endif
            </div>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>

{{-- ================= RELATIONSHIP VERIFICATION ================= --}}
<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Relationships to PDLs</h2></div>

  @if ($visitor->relationships->isEmpty())
    <p class="empty-note">No PDL relationships registered for this visitor yet.</p>
  @else
    <table>
      <thead><tr><th>PDL</th><th>Relationship</th><th>Priority</th><th>Status</th><th></th></tr></thead>
      <tbody>
        @foreach ($visitor->relationships as $rel)
        <tr>
          <td>
            @if ($rel->pdl)
              <a href="{{ route('pdl.show', $rel->pdl->pdl_id) }}" style="color:var(--text);text-decoration:none;font-weight:600;">
                {{ $rel->pdl->full_name }}
              </a>
            @else
              <span class="muted-cell">PDL not found</span>
            @endif
          </td>
          <td class="muted-cell">{{ $rel->relationshipLabel() }}</td>
          <td>
            @if ($rel->isHighPriority())
              <span class="pill" style="background:var(--blue-bg);color:var(--blue);">HIGH PRIORITY</span>
            @else
              <span class="muted-cell">Requires Verification</span>
            @endif
          </td>
          <td>
            <span class="badge {{ $rel->verification_status === 'verified' ? 'active' : ($rel->verification_status === 'rejected' ? 'rejected' : 'transferred') }}">
              <span class="dot"></span>{{ strtoupper($rel->verification_status) }}
            </span>
          </td>
          <td>
            <div style="display:flex;gap:8px;">
              @if ($rel->verification_status !== 'verified')
                <form method="POST" action="{{ route('visitor.relationships.verify', [$visitor->visitor_id, $rel->relationship_id]) }}">
                  @csrf
                  <button type="submit" class="row-action" style="color:var(--green);">Verify</button>
                </form>
              @endif
              @if ($rel->verification_status !== 'rejected')
                <form method="POST" action="{{ route('visitor.relationships.reject', [$visitor->visitor_id, $rel->relationship_id]) }}">
                  @csrf
                  <button type="submit" class="row-action" style="color:var(--red);">Reject</button>
                </form>
              @endif
            </div>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>

{{-- ================= ASSIGN VISIT ================= --}}
<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Assign Visit</h2></div>

  @if ($visitor->verification_status === 'rejected')
    <p class="empty-note">This visitor was rejected and cannot be assigned a visit.</p>
  @elseif ($visitor->account && $visitor->account->status !== 'active')
    <p class="empty-note">This visitor account is not active and cannot be assigned a visit.</p>
  @elseif ($assignableRelationships->isEmpty())
    <p class="empty-note">Register and verify a visitor↔PDL relationship before assigning a visit.</p>
  @else
    <form method="POST" action="{{ route('visitor.visits.assign', $visitor->visitor_id) }}" id="assignVisitForm">
      @csrf

      <div class="row cols-2">
        <div class="field-m">
          <label>Relationship / PDL</label>
          <select name="relationship_id" id="relationshipSelect" required>
            <option value="">— Select relationship —</option>
            @foreach ($assignableRelationships as $rel)
              <option
                value="{{ $rel->relationship_id }}"
                data-pdl-id="{{ $rel->pdl_id }}"
                data-classification="{{ $rel->pdl->classification }}"
                {{ (string) old('relationship_id') === (string) $rel->relationship_id ? 'selected' : '' }}
              >
                {{ $rel->pdl->full_name }} — {{ $rel->relationshipLabel() }}
                ({{ strtoupper($rel->verification_status) }})
              </option>
            @endforeach
          </select>
          <input type="hidden" name="pdl_id" id="pdlIdInput" value="{{ old('pdl_id') }}">
        </div>

        <div class="field-m">
          <label>Visit Schedule</label>
          <select name="schedule_id" id="scheduleSelect" required>
            <option value="">— Select a relationship first —</option>
          </select>
        </div>
      </div>

      <div class="row cols-2">
        <div class="field-m">
          <label>Confirmation Deadline (optional)</label>
          <input type="datetime-local" name="confirmation_deadline" value="{{ old('confirmation_deadline') }}">
          <div class="muted-cell" style="margin-top:6px;font-size:12px;">
            Leave blank to use the facility default confirmation window.
          </div>
        </div>
        <div class="field-m">
          <label>Initial Status</label>
          <input type="text" value="pending_confirmation" disabled>
        </div>
      </div>

      <button class="btn btn-blue" type="submit">Assign Visit</button>
    </form>

    {{-- Built in a PHP block first: Blade's json directive cannot parse a multi-line closure argument. --}}
    @php
      $scheduleOptionsByClassification = collect($schedulesByClassification ?? [])->map(function ($schedules) {
        return $schedules->map(function ($s) {
          $date = $s->schedule_date?->format('M j, Y');
          $start = substr((string) $s->time_slot_start, 0, 5);
          $end = substr((string) $s->time_slot_end, 0, 5);
          return [
            'id' => $s->schedule_id,
            'label' => "{$date} · {$start}–{$end} · {$s->capacityLabel()} open",
          ];
        })->values();
      });
    @endphp
    <script type="application/json" id="schedulesByClassification">@json($scheduleOptionsByClassification)</script>
    <script>
      (function () {
        const relationshipSelect = document.getElementById('relationshipSelect');
        const scheduleSelect = document.getElementById('scheduleSelect');
        const pdlIdInput = document.getElementById('pdlIdInput');
        const schedules = JSON.parse(document.getElementById('schedulesByClassification').textContent || '{}');
        const oldScheduleId = @json(old('schedule_id'));

        function refreshSchedules() {
          const option = relationshipSelect.options[relationshipSelect.selectedIndex];
          const classification = option ? option.getAttribute('data-classification') : null;
          const pdlId = option ? option.getAttribute('data-pdl-id') : '';
          pdlIdInput.value = pdlId || '';

          scheduleSelect.innerHTML = '';
          const placeholder = document.createElement('option');
          placeholder.value = '';
          placeholder.textContent = classification ? '— Select schedule —' : '— Select a relationship first —';
          scheduleSelect.appendChild(placeholder);

          const list = (classification && schedules[classification]) ? schedules[classification] : [];
          if (!list.length && classification) {
            const empty = document.createElement('option');
            empty.value = '';
            empty.textContent = 'No open schedules for this PDL classification';
            empty.disabled = true;
            scheduleSelect.appendChild(empty);
            return;
          }

          list.forEach(function (item) {
            const opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item.label;
            if (String(oldScheduleId) === String(item.id)) opt.selected = true;
            scheduleSelect.appendChild(opt);
          });
        }

        relationshipSelect.addEventListener('change', refreshSchedules);
        refreshSchedules();
      })();
    </script>
  @endif
</div>

{{-- ================= RECENT ASSIGNMENTS ================= --}}
@if (($visitor->visitRequests ?? collect())->isNotEmpty())
<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Recent Visit Assignments</h2></div>
  <table>
    <thead><tr><th>PDL</th><th>Schedule</th><th>Status</th><th>Assigned</th></tr></thead>
    <tbody>
      @foreach ($visitor->visitRequests as $vr)
      <tr>
        <td>{{ $vr->pdl?->full_name ?? '—' }}</td>
        <td class="muted-cell">
          @if ($vr->schedule)
            {{ $vr->schedule->schedule_date->format('M d, Y') }}
            · {{ substr($vr->schedule->time_slot_start, 0, 5) }}–{{ substr($vr->schedule->time_slot_end, 0, 5) }}
          @else
            —
          @endif
        </td>
        <td>
          <span class="badge transferred"><span class="dot"></span>{{ strtoupper(str_replace('_', ' ', $vr->status)) }}</span>
        </td>
        <td class="muted-cell">{{ optional($vr->assigned_at)->format('M d, Y') ?? '—' }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
</div>
@endif

{{-- ================= VISITOR HISTORY / FLAGS ================= --}}
<div class="panel">
  <div class="panel-header"><h2>History &amp; Flags</h2></div>

  @if ($visitor->flags->isEmpty())
    <p class="empty-note">No flags on file — clean history.</p>
  @else
    <table>
      <thead><tr><th>Type</th><th>Description</th><th>Status</th><th>Flagged By</th><th>Date</th><th></th></tr></thead>
      <tbody>
        @foreach ($visitor->flags as $flag)
        <tr>
          <td>{{ $flag->typeLabel() }}</td>
          <td class="muted-cell">{{ $flag->description }}</td>
          <td>
            <span class="badge {{ $flag->isActive() ? 'transferred' : 'active' }}">
              <span class="dot"></span>{{ strtoupper($flag->status) }}
            </span>
          </td>
          <td class="muted-cell">{{ $flag->flaggedBy?->full_name ?? '—' }}</td>
          <td class="muted-cell">{{ $flag->created_at->format('M d, Y') }}</td>
          <td>
            @if ($flag->isActive())
              <form method="POST" action="{{ route('visitor.flags.resolve', [$visitor->visitor_id, $flag->flag_id]) }}">
                @csrf
                <button type="submit" class="row-action">Resolve</button>
              </form>
            @endif
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  <details style="margin-top:14px;">
    <summary class="row-action" style="cursor:pointer;">Flag This Visitor</summary>
    <form method="POST" action="{{ route('visitor.flags.store', $visitor->visitor_id) }}" style="margin-top:12px;">
      @csrf
      <div class="row cols-2">
        <div class="field-m">
          <label>Flag Type</label>
          <select name="flag_type" required>
            <option value="denied_visit">Denied Visit</option>
            <option value="disciplinary_issue">Disciplinary Issue</option>
            <option value="officer_conflict">Officer Conflict</option>
            <option value="rule_violation">Rule Violation</option>
            <option value="prohibited_item_attempt">Prohibited Item Attempt</option>
            <option value="other">Other</option>
          </select>
        </div>
      </div>
      <div class="row">
        <div class="field-m"><label>Description</label><textarea name="description" required></textarea></div>
      </div>
      <button class="btn btn-blue" type="submit">Add Flag</button>
    </form>
  </details>
</div>
@endsection
