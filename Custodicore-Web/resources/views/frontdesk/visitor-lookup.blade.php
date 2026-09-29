@extends('layouts.frontdesk')

@section('title', 'Visitor Lookup')

@section('content')
    <div class="cc-card">
        <p class="text-card-title">Visitor Lookup</p>
        <p class="text-metadata text-text-secondary">Search by name or contact number to verify a visitor at the gate</p>

        <form method="GET" action="{{ route('frontdesk.visitor-lookup') }}" class="mt-md flex gap-sm">
            <input type="text" name="q" value="{{ $query }}" placeholder="Search by name or contact #…" class="cc-input flex-1" />
            <button type="submit" class="cc-btn-primary">Search</button>
        </form>

        <div class="mt-md space-y-sm">
            @forelse ($visitors as $visitor)
                <div class="flex items-start gap-sm border-b border-border pb-sm last:border-0 last:pb-0">
                    @include('admin.partials.avatar', ['name' => $visitor->full_name, 'size' => 'h-10 w-10'])
                    <div class="flex-1">
                        <div class="flex items-center gap-sm">
                            <p class="text-body font-semibold">{{ $visitor->full_name }}</p>
                            @include('admin.partials.status-chip', ['status' => $visitor->verification_status])
                            @if ($visitor->activeFlags->isNotEmpty())
                                <span class="cc-chip cc-chip-danger">{{ $visitor->activeFlags->count() }} active flag(s)</span>
                            @endif
                        </div>
                        <p class="text-metadata text-text-secondary">{{ $visitor->contact_number }}</p>
                        @foreach ($visitor->relationships as $rel)
                            <p class="text-metadata text-text-secondary">
                                {{ $rel->relationshipLabel() }} of {{ $rel->pdl?->full_name }} ({{ $rel->pdl?->pdl_number }})
                                — @include('admin.partials.status-chip', ['status' => $rel->verification_status])
                            </p>
                        @endforeach
                    </div>
                </div>
            @empty
                @if ($query !== '')
                    <p class="text-metadata text-text-secondary">No visitors matched "{{ $query }}".</p>
                @else
                    <p class="text-metadata text-text-secondary">Type a name or contact number above to search.</p>
                @endif
            @endforelse
        </div>
    </div>
@endsection
