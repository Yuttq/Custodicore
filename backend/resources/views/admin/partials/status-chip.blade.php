@php
    $map = [
        'active' => 'success', 'verified' => 'success', 'confirmed' => 'success', 'checked_out' => 'success',
        'completed' => 'success', 'eligible' => 'success', 'resolved' => 'success', 'pass' => 'success',
        'clean' => 'success', 'no_restriction' => 'success', 'lifted' => 'success', 'open' => 'success',
        'pending' => 'warning', 'pending_confirmation' => 'warning', 'awaiting_arrival' => 'warning',
        'flagged_for_review' => 'warning', 'requires_review' => 'warning', 'assigned' => 'warning',
        'rejected' => 'danger', 'declined' => 'danger', 'restricted' => 'danger', 'no_show' => 'danger',
        'has_prior_violations' => 'danger', 'flagged' => 'danger', 'suspended' => 'danger', 'inactive' => 'danger',
        'checked_in' => 'info', 'transferred' => 'neutral', 'deceased' => 'neutral', 'closed' => 'neutral', 'void' => 'neutral',
    ];
    $variant = $map[$status] ?? 'neutral';
    $label = ucwords(str_replace('_', ' ', (string) $status));
@endphp
<span class="cc-chip cc-chip-{{ $variant }}">{{ $label }}</span>
