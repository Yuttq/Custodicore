@php
    $map = [
        'active' => 'success', 'verified' => 'success', 'confirmed' => 'success', 'checked_out' => 'success',
        'completed' => 'success', 'eligible' => 'success', 'resolved' => 'success', 'pass' => 'success',
        'clean' => 'success', 'no_restriction' => 'success', 'lifted' => 'success', 'open' => 'success',
        'pending' => 'warning', 'pending_confirmation' => 'warning', 'awaiting_arrival' => 'warning',
        'flagged_for_review' => 'warning', 'requires_review' => 'warning', 'assigned' => 'warning',
        'rejected' => 'danger', 'declined' => 'danger', 'restricted' => 'danger', 'no_show' => 'danger',
        'has_prior_violations' => 'danger', 'flagged' => 'danger', 'suspended' => 'danger', 'inactive' => 'danger',
        'checked_in' => 'info', 'invited' => 'info', 'invite_expired' => 'warning', 'transferred' => 'neutral', 'deceased' => 'neutral', 'closed' => 'neutral', 'void' => 'neutral',
        'exit_unrecorded' => 'warning',
    ];
    $variant = $map[$status] ?? 'neutral';
    // Gate record closed by staff without a check-out (no exit time recorded).
    $label = $status === 'exit_unrecorded' ? 'Exit Not Recorded' : ucwords(str_replace('_', ' ', (string) $status));
    $dot = [
        'success' => 'bg-success', 'warning' => 'bg-warning',
        'danger' => 'bg-danger', 'info' => 'bg-info', 'neutral' => 'bg-text-secondary',
    ][$variant];
@endphp
<span class="cc-chip cc-chip-{{ $variant }} gap-xs">
    <span class="h-1.5 w-1.5 rounded-full {{ $dot }}"></span>
    {{ $label }}
</span>
