@extends('layouts.frontdesk')

@section('title', 'Walk-in Visitors')

@section('content')
    <div class="cc-card">
        <div class="flex flex-wrap items-start justify-between gap-sm">
            <div>
                <p class="text-card-title">Walk-in Visitors</p>
                <p class="text-metadata text-text-secondary">
                    Register visitors without the app. Records approves or rejects each request; approved visitors are checked in here.
                </p>
            </div>
            <a href="{{ route('frontdesk.walk-ins.create') }}" class="cc-btn-primary">New Walk-in</a>
        </div>

        @php
            $keep = fn (array $extra = []) => array_filter($extra + ['status' => $filter, 'q' => $query, 'date' => $date?->format('Y-m-d')]);
            $day = $date ?? today();
        @endphp
        <div class="mt-md flex flex-wrap items-center justify-between gap-md">
            <nav class="flex flex-wrap gap-xs" aria-label="Walk-in status">
                @foreach ($tabs as $tab)
                    <a href="{{ $tab['href'] }}" class="{{ $tab['active'] ? 'cc-btn-primary' : 'cc-btn-secondary' }} gap-xs" @if ($tab['active']) aria-current="page" @endif>
                        {{ $tab['label'] }}
                        <span class="cc-chip {{ $tab['active'] ? 'bg-white/20 text-white' : 'cc-chip-'.$tab['accent'] }}">{{ $tab['count'] }}</span>
                    </a>
                @endforeach
            </nav>

            <form method="GET" action="{{ route('frontdesk.walk-ins.index') }}" class="flex flex-wrap items-center justify-end gap-sm">
                @if ($filter)
                    <input type="hidden" name="status" value="{{ $filter }}" />
                @endif
                @include('frontdesk.partials.search-input', ['id' => 'walkInQuery', 'value' => $query, 'placeholder' => 'Search visitor or PDL…'])
                @include('frontdesk.partials.date-nav', [
                    'value' => $date?->format('Y-m-d'),
                    'prevUrl' => route('frontdesk.walk-ins.index', $keep(['date' => $day->copy()->subDay()->format('Y-m-d')])),
                    'nextUrl' => $day->isToday() ? null : route('frontdesk.walk-ins.index', $keep(['date' => $day->copy()->addDay()->format('Y-m-d')])),
                    'max' => today(),
                ])
                <button type="submit" class="sr-only">Search</button>
                @if ($query !== '' || $date)
                    <a href="{{ route('frontdesk.walk-ins.index', array_filter(['status' => $filter])) }}" class="cc-btn-secondary">Clear</a>
                @endif
            </form>
        </div>

        <div class="mt-md overflow-x-auto">
            <table class="w-full min-w-[720px] text-left">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md font-semibold">VISITOR</th>
                        <th class="py-sm pr-md font-semibold">VISITING (AS GIVEN)</th>
                        <th class="py-sm pr-md font-semibold">REGISTERED</th>
                        <th class="py-sm pr-md font-semibold">STATUS</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($walkIns as $row)
                        @php $visitor = $row['visitor']; @endphp
                        <tr class="text-body align-top">
                            <td class="py-sm pr-md">
                                <a href="{{ route('frontdesk.walk-ins.show', $visitor) }}" class="font-semibold text-primary-teal hover:underline">{{ $visitor->full_name }}</a>
                            </td>
                            <td class="py-sm pr-md">
                                {{ $visitor->intended_pdl_name ?: '—' }}
                                <span class="block text-metadata text-text-secondary">{{ $visitor->relationship_hint }}@if ($visitor->intended_pdl_number) · {{ $visitor->intended_pdl_number }}@endif</span>
                            </td>
                            <td class="py-sm pr-md">
                                {{ $visitor->created_at->format('H:i') }}
                                <span class="block text-metadata text-text-secondary">{{ $visitor->created_at->isToday() ? 'Today' : $visitor->created_at->format('M j') }}</span>
                            </td>
                            <td class="py-sm pr-md font-semibold {{ ['success' => 'text-success', 'warning' => 'text-warning', 'danger' => 'text-danger', 'info' => 'text-info'][$row['tone']] ?? 'text-text-secondary' }}">{{ $row['label'] }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-md text-metadata text-text-secondary">{{ $query !== '' || $date ? 'No walk-in visitors match.' : ($filter ? 'No walk-in visitors here right now.' : 'No walk-in visitors registered yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('frontdesk.partials.pagination', ['paginator' => $walkIns, 'noun' => 'visitor'])
    </div>
@endsection

@if (collect($walkIns->items())->contains(fn ($row) => in_array($row['key'], ['waiting', 'linking', 'assess'], true)))
    @push('scripts')
        <script>
            // Someone is waiting on the Records Officer: refresh so the status changes show up.
            setTimeout(() => { if (!document.hidden) location.reload(); }, 20000);
        </script>
    @endpush
@endif
