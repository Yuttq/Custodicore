{{--
  "Approve" for one pending visitor↔PDL relationship: approves its files and
  the relationship, and counts as the officer's confirmation of what the
  pop-up states ("no immediate family"; the minor's companion, taken from
  the guardian named at registration). Used on the visitor page and in
  Review Queue → PDL Links. Expects $rel and $visitor.
    $compact — true in the PDL Links list (blue button instead of a link)
--}}
@php
  $compact = $compact ?? false;
  $items = \App\Services\RelationshipRequirements::for($rel);
  $ready = \App\Services\RelationshipApproval::ready($items);
  $blockers = \App\Services\RelationshipApproval::blockers($items);
  $toConfirm = \App\Services\RelationshipApproval::confirmationsNeeded($items);
  $pdlName = $rel->pdl?->full_name ?? 'this PDL';
  $companion = \App\Services\RelationshipApproval::companion($rel);
  $askCompanion = array_key_exists('accompanying_guardian', $toConfirm) && $companion === null;
  // What clicking Approve confirms, in plain words for the pop-up.
  $confirms = collect($toConfirm)->keys()->map(fn ($key) => match ($key) {
      'no_immediate_family' => "{$pdlName} has no immediate family available to visit",
      'accompanying_guardian' => $companion ? "the minor will be accompanied by {$companion} (their registered guardian)" : 'the minor will be accompanied by the adult named below',
      default => \App\Services\RelationshipRequirements::CONFIRMATIONS[$key][0] ?? $key,
  })->all();
@endphp
<form method="POST" action="{{ route('visitor.relationships.verify', [$visitor->visitor_id, $rel->relationship_id]) }}" style="margin:0;"
      data-confirm="Approve {{ $visitor->full_name }} as {{ $rel->relationshipLabel() }} of {{ $pdlName }}? This approves its files and the relationship together.{{ $confirms ? ' By approving, you confirm ' . implode(', and ', $confirms) . '.' : '' }}"
      data-confirm-title="Approve relationship" data-confirm-ok="Approve">
  @csrf
  @if ($ready && $askCompanion)
    {{-- Older accounts without a registered guardian: name the companion here. --}}
    <input type="text" name="accompanying_adult" maxlength="150" required placeholder="Name of the accompanying parent or guardian"
           aria-label="Name of the accompanying parent or guardian" class="approve-confirm-note" style="margin-bottom:6px;">
  @endif
  @if ($ready)
    <button type="submit" class="{{ $compact ? 'btn btn-blue' : 'row-action' }}" style="{{ $compact ? 'padding:6px 14px;' : 'color:var(--green);' }}">Approve</button>
  @else
    <button type="submit" class="{{ $compact ? 'btn btn-blue' : 'row-action' }}" disabled
            style="{{ $compact ? 'padding:6px 14px;' : 'color:var(--green);opacity:.45;cursor:not-allowed;' }}"
            title="Still needed: {{ implode(', ', $blockers) }}">Approve</button>
  @endif
</form>
