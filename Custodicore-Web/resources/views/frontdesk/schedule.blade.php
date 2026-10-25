@extends('layouts.frontdesk')

@section('title', 'Visit Schedule')

@section('content')
    @php
        $dateParam = $date?->format('Y-m-d');
        $day = $date ?? today();
        $keep = fn (array $extra = []) => array_filter($extra + ['date' => $dateParam, 'q' => $query]);
        $dayLink = fn ($d) => route('frontdesk.schedule', [$tab] + $keep(['date' => $d->format('Y-m-d')]));
    @endphp
    <div class="cc-card">
        <div class="flex flex-wrap items-center justify-between gap-md">
            <nav class="flex flex-wrap gap-xs" aria-label="Visit schedule">
                @foreach ($lists as $key => $other)
                    <a href="{{ route('frontdesk.schedule', array_filter([$key, 'date' => $dateParam])) }}"
                       class="{{ $key === $tab ? 'cc-btn-primary' : 'cc-btn-secondary' }} gap-xs"
                       @if ($key === $tab) aria-current="page" @endif>
                        {{ $other['label'] }}
                        {{-- Without a day the counts update on their own; a chosen day stays as it is. --}}
                        <span @unless ($date) data-stat="{{ $key }}" @endunless class="cc-chip {{ $key === $tab ? 'bg-white/20 text-white' : 'cc-chip-'.$other['accent'] }}">{{ $counts[$key] }}</span>
                    </a>
                @endforeach
                <a href="{{ route('frontdesk.schedule', array_filter(['sessions', 'date' => $dateParam])) }}"
                   class="{{ $tab === 'sessions' ? 'cc-btn-primary' : 'cc-btn-secondary' }}"
                   @if ($tab === 'sessions') aria-current="page" @endif>Upcoming Sessions</a>
            </nav>

            {{-- Search (not on Sessions) and day. No day: everything current. --}}
            <form method="GET" action="{{ route('frontdesk.schedule', $tab) }}" class="flex flex-wrap items-center justify-end gap-sm">
                @if ($tab !== 'sessions')
                    @include('frontdesk.partials.search-input', ['id' => 'scheduleQuery', 'value' => $query, 'placeholder' => 'Search visitor or PDL…'])
                @endif

                @include('frontdesk.partials.date-nav', [
                    'value' => $dateParam,
                    'prevUrl' => $dayLink($day->copy()->subDay()),
                    'nextUrl' => $dayLink($day->copy()->addDay()),
                ])

                {{-- Enter in the search box also submits. --}}
                <button type="submit" class="sr-only">Search</button>
                @if ($date || $query !== '')
                    <a href="{{ route('frontdesk.schedule', $tab) }}" class="cc-btn-secondary">Clear</a>
                @endif
            </form>
        </div>

        @if ($tab === 'sessions')
            <div class="mt-lg overflow-x-auto border-t border-border pt-md">
                <table class="w-full text-left text-body">
                    <thead>
                        <tr class="border-b border-border text-section-label text-text-secondary">
                            <th class="py-sm pr-md">Date</th>
                            <th class="py-sm pr-md">Time Slot</th>
                            <th class="py-sm pr-md">PDL Classification</th>
                            <th class="py-sm pr-md">Capacity</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($schedules as $schedule)
                            <tr class="{{ $schedule['is_today'] ? 'bg-info/5' : '' }}">
                                <td class="py-sm pr-md font-semibold">
                                    {{ $schedule['schedule_date'] }}
                                    @if ($schedule['is_today'])
                                        <span class="cc-chip cc-chip-info ml-xs">Today</span>
                                    @endif
                                </td>
                                <td class="py-sm pr-md">{{ $schedule['time_slot'] }}</td>
                                <td class="py-sm pr-md">
                                    <span class="cc-chip {{ $schedule['classification'] === 'drug_related' ? 'cc-chip-danger' : 'cc-chip-info' }}">
                                        {{ ucwords(str_replace('_', ' ', $schedule['classification'])) }}
                                    </span>
                                </td>
                                <td class="py-sm pr-md text-text-secondary">{{ $schedule['capacity'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @include('frontdesk.partials.pagination', ['paginator' => $schedules, 'noun' => 'session'])
        @else
            <div class="mt-lg overflow-x-auto border-t border-border pt-md">
                <table class="w-full min-w-[820px] text-left">
                    <thead>
                        <tr class="border-b border-border text-section-label text-text-secondary">
                            <th class="py-sm pr-md font-semibold">VISITOR</th>
                            <th class="py-sm pr-md font-semibold">PDL VISITED</th>
                            <th class="py-sm pr-md font-semibold">DATE &amp; SESSION</th>
                            <th class="py-sm pr-md font-semibold">CHECK-IN</th>
                            <th class="py-sm pr-md font-semibold">CHECK-OUT</th>
                            <th class="py-sm font-semibold">STATUS</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($visits as $visit)
                            @php
                                $visitor = $visit->visitor;
                                $pdl = $visit->pdl;
                                $in = $visit->checkins->max('check_in_time');
                                $out = $visit->checkins->max('check_out_time');
                                $inside = $visit->checkins->contains('status', 'checked_in');
                            @endphp
                            <tr class="text-body align-top">
                                <td class="py-sm pr-md">
                                    @if ($visitor)
                                        <a href="{{ route('frontdesk.visitor-lookup.show', $visitor->visitor_id) }}" class="font-semibold text-primary-teal hover:underline">{{ $visitor->full_name }}</a>
                                        <span class="block text-metadata text-text-secondary">Visitor ID: {{ $visitor->visitor_id }}</span>
                                    @else
                                        <span class="font-semibold">Unknown visitor</span>
                                    @endif
                                </td>
                                <td class="py-sm pr-md">
                                    {{ $pdl?->full_name ?? '—' }}
                                    @if ($pdl?->pdl_number)
                                        <span class="block text-metadata text-text-secondary">{{ $pdl->pdl_number }}</span>
                                    @endif
                                </td>
                                <td class="py-sm pr-md">
                                    @if ($date = $visit->schedule?->schedule_date)
                                        <span class="block font-semibold">{{ $date->isToday() ? 'Today' : $date->format('D, M j') }}</span>
                                    @endif
                                    {{-- A whole-day visit lists both sessions. --}}
                                    {{ $visit->sessionSchedules->map(fn ($s) => substr($s->time_slot_start, 0, 5).'–'.substr($s->time_slot_end, 0, 5))->implode(' · ') ?: '—' }}
                                </td>
                                <td class="py-sm pr-md">{{ $in ? \Illuminate\Support\Carbon::parse($in)->format('H:i') : '—' }}</td>
                                <td class="py-sm pr-md">{{ $out && ! $inside ? \Illuminate\Support\Carbon::parse($out)->format('H:i') : '—' }}</td>
                                <td class="py-sm">
                                    @if ($inside)
                                        <span class="font-semibold text-success">Inside</span>
                                    @elseif ($visit->status === 'completed')
                                        <span class="font-semibold text-warning">Checked out</span>
                                    @elseif ($visit->checkins->isNotEmpty())
                                        <span class="font-semibold text-info">On break</span>
                                    @else
                                        <span class="font-semibold text-info">Expected</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-md text-metadata text-text-secondary">{{ $query !== '' ? "No visitors match \"{$query}\"." : ($date ? 'No visitors on this day.' : 'No visitors here right now.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @include('frontdesk.partials.pagination', ['paginator' => $visits, 'noun' => 'visitor'])
        @endif
    </div>
@endsection

