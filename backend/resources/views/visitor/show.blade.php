@extends('layouts.app')
@section('title', $visitor->full_name . ' — CustodiCore')

@section('content')
<div class="topbar">
  <h1>{{ $visitor->full_name }}</h1>
  <div class="right">
    <a class="btn btn-outline-neutral" href="{{ route('visitor.index') }}">← Back to Visitor Management</a>
  </div>
</div>

<div class="panel" style="margin-bottom:20px;">
  <div class="panel-header"><h2>Profile</h2></div>
  <div class="row cols-3" style="margin-bottom:0;">
    <div><div class="stat-label">Date of Birth</div><div>{{ $visitor->date_of_birth->format('M d, Y') }}</div></div>
    <div><div class="stat-label">Gender</div><div>{{ ucfirst($visitor->gender) }}</div></div>
    <div><div class="stat-label">Contact Number</div><div>{{ $visitor->contact_number }}</div></div>
  </div>
  <div class="row cols-2" style="margin-top:16px;margin-bottom:0;">
    <div><div class="stat-label">Address</div><div>{{ $visitor->address ?? '—' }}</div></div>
    <div><div class="stat-label">Emergency Contact</div><div>{{ $visitor->emergency_contact_name ?? '—' }} {{ $visitor->emergency_contact_number ? '('.$visitor->emergency_contact_number.')' : '' }}</div></div>
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
            <span class="badge {{ $doc->verification_status === 'verified' ? 'active' : ($doc->verification_status === 'rejected' ? 'released' : 'transferred') }}">
              <span class="dot"></span>{{ strtoupper($doc->verification_status) }}
            </span>
          </td>
          <td class="muted-cell">{{ $doc->verifiedBy?->full_name ?? '—' }}</td>
          <td>
            <div style="display:flex;gap:8px;">
              @if ($doc->verification_status !== 'verified')
                <form method="POST" action="{{ route('visitor.ids.verify', [$visitor->visitor_id, $doc->visitor_id_doc_id]) }}">
                  @csrf
                  <button type="submit" class="row-action" style="color:var(--green);">Verify →</button>
                </form>
              @endif
              @if ($doc->verification_status !== 'rejected')
                <form method="POST" action="{{ route('visitor.ids.reject', [$visitor->visitor_id, $doc->visitor_id_doc_id]) }}">
                  @csrf
                  <button type="submit" class="row-action" style="color:var(--red);">Reject →</button>
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
            <span class="badge {{ $rel->verification_status === 'verified' ? 'active' : ($rel->verification_status === 'rejected' ? 'released' : 'transferred') }}">
              <span class="dot"></span>{{ strtoupper($rel->verification_status) }}
            </span>
          </td>
          <td>
            <div style="display:flex;gap:8px;">
              @if ($rel->verification_status !== 'verified')
                <form method="POST" action="{{ route('visitor.relationships.verify', [$visitor->visitor_id, $rel->relationship_id]) }}">
                  @csrf
                  <button type="submit" class="row-action" style="color:var(--green);">Verify →</button>
                </form>
              @endif
              @if ($rel->verification_status !== 'rejected')
                <form method="POST" action="{{ route('visitor.relationships.reject', [$visitor->visitor_id, $rel->relationship_id]) }}">
                  @csrf
                  <button type="submit" class="row-action" style="color:var(--red);">Reject →</button>
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
                <button type="submit" class="row-action">Resolve →</button>
              </form>
            @endif
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  <details style="margin-top:14px;">
    <summary class="row-action" style="cursor:pointer;">+ Flag This Visitor</summary>
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