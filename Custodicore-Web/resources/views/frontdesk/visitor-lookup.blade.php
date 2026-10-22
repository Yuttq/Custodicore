@extends('layouts.frontdesk')

@section('title', 'Visitor Lookup')

@section('content')
    <div class="cc-card">
        <p class="text-card-title">Visitor Lookup</p>
        <p class="text-metadata text-text-secondary">Search by name or contact number, then open the visitor&rsquo;s profile.</p>

        <form method="GET" action="{{ route('frontdesk.visitor-lookup') }}" class="mt-md flex flex-wrap items-center gap-sm">
            @include('frontdesk.partials.search-input', ['id' => 'visitorLookupQuery', 'value' => $query, 'placeholder' => 'Search by name or contact number…'])
            <button type="submit" class="cc-btn-primary">Search</button>
            @if ($query !== '')
                <a href="{{ route('frontdesk.visitor-lookup') }}" class="cc-btn-secondary">Clear</a>
            @endif
        </form>

        <div class="mt-md space-y-sm">
            @forelse ($visitors as $visitor)
                <a href="{{ route('frontdesk.visitor-lookup.show', [$visitor->visitor_id, 'q' => $query]) }}"
                   class="-mx-sm flex items-start gap-sm rounded-lg border-b border-border px-sm py-sm transition last:border-0 hover:bg-background focus:bg-background focus:outline-none">
                    @include('admin.partials.avatar', ['name' => $visitor->full_name, 'size' => 'h-10 w-10'])
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-sm">
                            <p class="text-body font-semibold">{{ $visitor->full_name }}</p>
                            @include('admin.partials.status-chip', ['status' => $visitor->verification_status])
                            @if ($visitor->activeFlags->isNotEmpty())
                                <span class="cc-chip cc-chip-danger">{{ $visitor->activeFlags->count() }} active {{ \Illuminate\Support\Str::plural('flag', $visitor->activeFlags->count()) }}</span>
                            @endif
                        </div>
                        <p class="text-metadata text-text-secondary">Visitor ID: {{ $visitor->visitor_id }} · {{ $visitor->contact_number ?: 'No contact number' }}</p>
                        @foreach ($visitor->relationships as $rel)
                            <p class="text-metadata text-text-secondary">
                                {{ $rel->relationshipLabel() }} of {{ $rel->pdl?->full_name }} ({{ $rel->pdl?->pdl_number }})
                            </p>
                        @endforeach
                    </div>
                    <span class="shrink-0 self-center text-metadata font-semibold text-primary-teal">View profile ›</span>
                </a>
            @empty
                @if ($tooShort)
                    <p class="text-metadata text-text-secondary">Type at least 2 characters to search.</p>
                @elseif ($query !== '')
                    <p class="text-metadata text-text-secondary">No visitors matched "{{ $query }}".</p>
                @else
                    <p class="text-metadata text-text-secondary">Type a name or contact number above to search.</p>
                @endif
            @endforelse
        </div>
    </div>
@endsection
