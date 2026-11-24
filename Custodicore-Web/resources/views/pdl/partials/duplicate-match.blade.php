{{--
  Register PDL: the name + date of birth entered match a PDL already on file
  (PdlController::validateProfile flashes duplicate_pdl_id). Almost always the
  same person, so this offers the existing record first: re-admit a released
  or transferred PDL there. A different person with the same name and birth
  date can still be registered after the officer confirms it, with a reason
  (written to the audit log).
    $match — the Pdl already on file
--}}
@php
  $statusLabels = ['active' => 'In custody', 'released' => 'Released', 'transferred' => 'Transferred', 'deceased' => 'Deceased'];
  $confirming = (bool) old('not_duplicate');
@endphp
<div class="dup-match" role="alert">
  <p class="dup-match-title">This PDL may already be registered</p>
  <p class="dup-match-text">
    A PDL with the same name and date of birth is already on file. Check the photo and details below before registering a new record.
  </p>

  <div class="dup-match-card">
    @include('pdl.partials.avatar', ['pdl' => $match, 'class' => 'thumb'])
    <div class="dup-match-body">
      <div class="dup-match-name">
        <a href="{{ route('pdl.show', $match->pdl_id) }}" target="_blank" rel="noopener">{{ $match->listName() }}</a>
        <span class="badge {{ $match->custody_status }}"><span class="dot"></span>{{ $statusLabels[$match->custody_status] ?? ucfirst($match->custody_status) }}</span>
      </div>
      <div class="muted-cell">
        {{ $match->pdl_number }}
        · Born {{ $match->date_of_birth?->format('M j, Y') ?? '—' }}
        · Admitted {{ $match->admission_date?->format('M j, Y') ?? '—' }}
        @if ($match->cell_block) · {{ $match->cell_block }}@endif
      </div>
      <div class="muted-cell">Offense: {{ $match->primaryOffense() ?? 'No case on file' }}</div>
    </div>
  </div>

  <p class="dup-match-text" style="margin-top:12px;">
    @if ($match->canBeReadmitted())
      <strong>Same person coming back?</strong> Re-admit them on their existing record instead. They keep their PDL number, cases, linked visitors and visit history.
    @elseif ($match->custody_status === 'active')
      <strong>This PDL is already in custody.</strong> If it is the same person, don't register them again. Add a new case to their record under Legal Records instead.
    @else
      <strong>This PDL is recorded as deceased.</strong> Check the name and date of birth carefully.
    @endif
  </p>
  <div class="dup-match-actions">
    @if ($match->canBeReadmitted())
      <a class="btn btn-blue" href="{{ route('pdl.show', ['pdl' => $match->pdl_id, 'readmit' => 1]) }}#readmit">Re-admit {{ $match->pdl_number }}</a>
    @endif
    <a class="btn btn-outline-neutral" href="{{ route('pdl.show', $match->pdl_id) }}">Open existing record</a>
  </div>

  <details class="dup-match-other" {{ $confirming || $errors->has('duplicate_reason') ? 'open' : '' }}>
    <summary>It is a different person</summary>
    <p class="field-hint" style="margin:8px 0 10px;">
      First check for a suffix (Jr., Sr., III). A father and son usually differ only by that. Set it under Personal Details and the record no longer matches.
      Only confirm below if the names and birth dates are truly the same.
    </p>
    <label class="dup-match-check">
      <input type="checkbox" name="not_duplicate" value="1" {{ $confirming ? 'checked' : '' }}>
      I checked the existing record and this is a different person.
    </label>
    <div class="field-m" style="margin-top:10px;">
      <label>How did you confirm it?</label>
      <input type="text" name="duplicate_reason" value="{{ old('duplicate_reason') }}" maxlength="255" minlength="10"
             placeholder="e.g. Different face in the photo; different parents per commitment order">
      <p class="field-hint">Saved to the audit log with this registration.</p>
      @error('duplicate_reason')<p class="field-hint" style="color:var(--red-text);">{{ $message }}</p>@enderror
    </div>
  </details>
</div>
