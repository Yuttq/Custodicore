@extends('layouts.frontdesk')

@section('title', 'Visitor Lookup')

@section('content')
    @php
        // Coloured status text, as on the Walk-in Visitors list.
        $statusTone = fn (?string $status) => match ($status) {
            'verified' => 'text-success',
            'pending' => 'text-warning',
            'rejected' => 'text-danger',
            default => 'text-text-secondary',
        };
    @endphp

    <div class="cc-card">
        <div class="flex flex-wrap items-start justify-between gap-sm">
            <div>
                <p class="text-card-title">Visitor Lookup</p>
                <p class="text-metadata text-text-secondary">Search by name or contact number, then open the visitor&rsquo;s profile.</p>
            </div>

            <form method="GET" action="{{ route('frontdesk.visitor-lookup') }}" class="flex flex-wrap items-center justify-end gap-sm">
                @include('frontdesk.partials.search-input', ['id' => 'visitorLookupQuery', 'value' => $query, 'placeholder' => 'Search by name or contact number…'])
                <button type="submit" class="cc-btn-primary">Search</button>
                @if ($query !== '')
                    <a href="{{ route('frontdesk.visitor-lookup') }}" class="cc-btn-secondary">Clear</a>
                @endif
            </form>
        </div>

        <div class="mt-md overflow-x-auto">
            <table class="w-full min-w-[720px] text-left">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md font-semibold">VISITOR</th>
                        <th class="py-sm pr-md font-semibold">CONTACT</th>
                        <th class="py-sm pr-md font-semibold">PDLS THEY MAY VISIT</th>
                        <th class="py-sm pr-md font-semibold">STATUS</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($visitors as $visitor)
                        @php $profileUrl = route('frontdesk.visitor-lookup.show', [$visitor->visitor_id, 'q' => $query]); @endphp
                        <tr class="cursor-pointer text-body align-top transition-colors hover:bg-background" data-row-href="{{ $profileUrl }}">
                            <td class="py-sm pr-md">
                                <a href="{{ $profileUrl }}" class="font-semibold text-primary-teal hover:underline">{{ $visitor->full_name }}</a>
                                <span class="block text-metadata text-text-secondary">Visitor ID {{ $visitor->visitor_id }}</span>
                            </td>
                            <td class="py-sm pr-md">{{ $visitor->contact_number && $visitor->contact_number !== 'N/A' ? $visitor->contact_number : '—' }}</td>
                            <td class="py-sm pr-md">
                                @forelse ($visitor->relationships as $rel)
                                    <span class="block">
                                        {{ $rel->pdl?->full_name ?? '—' }}
                                        <span class="text-metadata text-text-secondary">· {{ $rel->relationshipLabel() }}</span>
                                    </span>
                                @empty
                                    <span class="text-text-secondary">—</span>
                                @endforelse
                            </td>
                            <td class="py-sm pr-md">
                                <span class="font-semibold {{ $statusTone($visitor->verification_status) }}">{{ ucfirst($visitor->verification_status ?? '—') }}</span>
                                @if ($visitor->activeFlags->isNotEmpty())
                                    <span class="block text-metadata font-semibold text-danger">{{ $visitor->activeFlags->count() }} active {{ \Illuminate\Support\Str::plural('flag', $visitor->activeFlags->count()) }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-md text-metadata text-text-secondary">
                                @if ($tooShort)
                                    Type at least 2 characters to search.
                                @elseif ($query !== '')
                                    No visitors matched "{{ $query }}".
                                @else
                                    Type a name or contact number above to search.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    /* Clicking anywhere on a row opens the visitor's profile. */
    document.addEventListener('click', e => {
        const row = e.target.closest('tr[data-row-href]');
        if (!row || e.target.closest('a, button, input, select, textarea, label')) return;
        if (window.getSelection()?.toString()) return;
        if (e.ctrlKey || e.metaKey) window.open(row.dataset.rowHref, '_blank');
        else window.location.href = row.dataset.rowHref;
    });
</script>
@endpush
