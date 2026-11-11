@extends('layouts.frontdesk')

@section('title', 'Visitor Profile')

@php
    // Only flags still in force (not resolved, not past their time limit).
    $activeFlags = $visitor->flags->filter->isActive();
    $plural = fn (int $n, string $word) => $n . ' ' . \Illuminate\Support\Str::plural($word, $n);

    $details = [
        'Contact' => $visitor->contact_number ?: '—',
        'Email' => $visitor->account?->email ?: '—',
        'Born' => ($visitor->date_of_birth?->format('M d, Y') ?? '—') . ($age !== null ? " ({$age})" : ''),
        'Sex' => $visitor->gender ? ucfirst($visitor->gender) : '—',
        'Address' => $visitor->address ?: '—',
    ];
    if ($visitor->emergency_contact_name || $visitor->emergency_contact_number) {
        $details['Emergency'] = trim(($visitor->emergency_contact_name ?? '')
            . ($visitor->emergency_contact_number ? ' (' . $visitor->emergency_contact_number . ')' : ''));
    }
@endphp

@section('header_actions')
    <a href="{{ route('frontdesk.visitor-lookup', array_filter(['q' => $backQuery])) }}" class="cc-btn-secondary">Back to Visitor Lookup</a>
@endsection

@section('content')
    {{-- Warnings come first: an officer must see them before anything else. --}}
    @if ($activeFlags->isNotEmpty())
        <div class="cc-card border-l-4 border-l-danger" role="alert">
            <p class="text-card-title text-danger">
                {{ $activeFlags->count() }} active {{ \Illuminate\Support\Str::plural('flag', $activeFlags->count()) }} on this visitor
            </p>
            <ul class="mt-sm space-y-xs">
                @foreach ($activeFlags as $flag)
                    <li class="text-body">
                        <span class="font-semibold">{{ ucwords(str_replace('_', ' ', $flag->flag_type)) }}:</span>
                        {{ $flag->description }}
                        <span class="text-metadata text-text-secondary">· {{ $flag->created_at?->format('M d, Y') }} · {{ $flag->durationLabel() }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($visitor->verification_status !== 'verified')
        <div class="cc-card border-l-4 border-l-warning" role="status">
            <p class="text-body font-semibold">This visitor's account is not verified yet.</p>
            <p class="text-metadata text-text-secondary">Visits can only be checked in once the Records Officer approves the account.</p>
        </div>
    @endif

    {{-- Header: a large photo so the gate can match the face, then name, status and one line of numbers. --}}
    <div class="cc-card">
        <div class="flex flex-col gap-md sm:flex-row sm:items-center">
            @if ($photoUrl = $visitor->photoUrl())
                <a href="{{ $photoUrl }}" target="_blank" rel="noopener" title="Open full-size photo" class="shrink-0">
                    <img src="{{ $photoUrl }}" alt="Photo of {{ $visitor->full_name }}" class="h-24 w-24 rounded-lg border border-border object-cover">
                </a>
            @else
                @include('admin.partials.avatar', ['name' => $visitor->full_name, 'size' => 'h-24 w-24'])
            @endif

            <div class="min-w-0 flex-1 space-y-xs">
                <div class="flex flex-wrap items-center gap-sm">
                    <h2 class="text-page-title">{{ $visitor->full_name }}</h2>
                    @include('admin.partials.status-chip', ['status' => $visitor->verification_status])
                    @if ($activeFlags->isNotEmpty())
                        <span class="cc-chip cc-chip-danger">{{ $plural($activeFlags->count(), 'active flag') }}</span>
                    @endif
                </div>
                <p class="text-metadata text-text-secondary">
                    Visitor ID <span class="font-semibold text-text-primary">{{ $visitor->visitor_id }}</span>
                    @if ($visitor->verified_at)
                        · Verified {{ $visitor->verified_at->format('M d, Y') }}
                    @else
                        · Registered {{ $visitor->created_at?->format('M d, Y') ?? '—' }}
                    @endif
                </p>
                <p class="text-metadata text-text-secondary">
                    {{ $plural($stats['totalVisits'], 'completed visit') }}
                    · {{ $plural($stats['gateEntries'], 'gate entry') }}
                    · <span class="{{ $stats['noShows'] ? 'font-semibold text-danger' : '' }}">{{ $plural($stats['noShows'], 'no-show') }}</span>
                    @if ($stats['lastVisit'])
                        · Last visit {{ \Illuminate\Support\Carbon::parse($stats['lastVisit'])->format('M d, Y') }}
                    @endif
                </p>
                @if ($todayVisits->isEmpty())
                    <p class="text-metadata text-text-secondary">Today: no visit scheduled.</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Today's visit: only when there is one. --}}
    @if ($todayVisits->isNotEmpty())
        <div class="cc-card border-l-4 border-l-primary-teal">
            <div class="flex flex-wrap items-center justify-between gap-sm">
                <p class="text-card-title">Today's Visit <span class="text-metadata font-normal text-text-secondary">· {{ now()->format('l, M d') }}</span></p>
                {{-- Only when there is something to do at the gate: arriving, or inside. --}}
                @if ($todayVisits->contains(fn ($today) => in_array($today['gate']['status'], ['awaiting_arrival', 'checked_in'], true)))
                    <a href="{{ route('frontdesk.checkin-checkout') }}" class="cc-btn-primary">Go to Check-In / Check-Out</a>
                @endif
            </div>

            <div class="mt-sm divide-y divide-border">
                @foreach ($todayVisits as $today)
                    <div class="flex flex-col gap-xs py-sm sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-body font-semibold">
                                Visiting {{ $today['visit']->pdl?->full_name ?? '—' }}
                                <span class="font-normal text-text-secondary">({{ $today['visit']->pdl?->pdl_number ?? '—' }})</span>
                            </p>
                            <p class="text-metadata text-text-secondary">{{ $today['sessions'] }}</p>
                        </div>
                        <div class="flex items-center gap-sm">
                            <span class="text-metadata text-text-secondary">{{ $today['gate']['label'] }}</span>
                            @include('admin.partials.status-chip', ['status' => $today['gate']['status']])
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-md lg:grid-cols-2">
        {{-- Personal information --}}
        <div class="cc-card">
            <p class="text-section-label uppercase text-text-secondary">Personal Information</p>
            <dl class="mt-xs divide-y divide-border text-metadata">
                @foreach ($details as $name => $value)
                    <div class="flex justify-between gap-md py-xs">
                        <dt class="text-text-secondary">{{ $name }}</dt>
                        <dd class="break-all text-right font-semibold">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        {{-- IDs on file: compare with the ID the visitor shows at the gate. --}}
        <div class="cc-card">
            <p class="text-section-label uppercase text-text-secondary">IDs on File</p>
            <ul class="mt-xs divide-y divide-border">
                @forelse ($visitor->idDocuments as $doc)
                    @php
                        $hasPhoto = $doc->fileDisk() !== null;
                        // 'PENDING' is stored when the visitor gave no number (not a real ID number).
                        $idNumber = filled($doc->id_number) && strtoupper($doc->id_number) !== 'PENDING' ? $doc->id_number : null;
                    @endphp
                    <li class="flex flex-wrap items-center justify-between gap-sm py-sm">
                        <div class="text-metadata">
                            <p class="font-semibold text-text-primary">{{ $doc->typeLabel() }}</p>
                            <p class="text-text-secondary">
                                @if ($idNumber)
                                    No. <span class="font-semibold text-text-primary">{{ $idNumber }}</span>
                                @else
                                    No number given
                                @endif
                                · Added {{ $doc->uploaded_at ? \Illuminate\Support\Carbon::parse($doc->uploaded_at)->format('M d, Y') : '—' }}
                            </p>
                        </div>
                        <div class="flex items-center gap-sm">
                            @include('admin.partials.status-chip', ['status' => $doc->verification_status])
                            @if ($hasPhoto)
                                <a href="{{ route('frontdesk.visitor-lookup.id-photo', [$visitor->visitor_id, $doc->visitor_id_doc_id]) }}"
                                   target="_blank" rel="noopener"
                                   class="text-metadata font-semibold text-primary-teal hover:underline">View photo</a>
                            @else
                                <span class="text-metadata text-text-secondary">No photo</span>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="py-sm text-metadata text-text-secondary">No IDs on file.</li>
                @endforelse
            </ul>
        </div>

        {{-- PDL relationships --}}
        <div class="cc-card">
            <p class="text-section-label uppercase text-text-secondary">PDLs This Visitor May Visit</p>
            <ul class="mt-xs divide-y divide-border">
                @forelse ($visitor->relationships as $rel)
                    <li class="flex flex-wrap items-center justify-between gap-sm py-sm text-metadata">
                        <div>
                            <p class="font-semibold text-text-primary">
                                {{ $rel->pdl?->full_name ?? '—' }}
                                <span class="font-normal text-text-secondary">({{ $rel->pdl?->pdl_number ?? '—' }})</span>
                            </p>
                            <p class="text-text-secondary">
                                {{ $rel->relationshipLabel() }}@if ($rel->pdl?->cell_block) · {{ $rel->pdl->cell_block }}@endif
                            </p>
                        </div>
                        @include('admin.partials.status-chip', ['status' => $rel->verification_status ?? 'pending'])
                    </li>
                @empty
                    <li class="py-sm text-metadata text-text-secondary">No PDL relationships on record.</li>
                @endforelse
            </ul>
        </div>

        {{-- Upcoming visits (after today) --}}
        <div class="cc-card">
            <p class="text-section-label uppercase text-text-secondary">Upcoming Visits</p>
            <ul class="mt-xs divide-y divide-border">
                @forelse ($upcomingVisits as $upcoming)
                    <li class="flex flex-wrap items-center justify-between gap-sm py-sm text-metadata">
                        <div>
                            <p class="font-semibold text-text-primary">{{ $upcoming['visit']->schedule?->schedule_date?->format('D, M d, Y') ?? '—' }}</p>
                            <p class="text-text-secondary">
                                {{ $upcoming['sessions'] }} · Visiting {{ $upcoming['visit']->pdl?->full_name ?? '—' }}
                            </p>
                        </div>
                        @include('admin.partials.status-chip', ['status' => $upcoming['visit']->status])
                    </li>
                @empty
                    <li class="py-sm text-metadata text-text-secondary">No upcoming visits.</li>
                @endforelse
            </ul>
        </div>
    </div>

    {{-- Gate history: one line when empty, the table only when there are records. --}}
    <div class="cc-card">
        @if ($gateHistory->isEmpty())
            <p class="text-metadata text-text-secondary">
                <span class="text-section-label uppercase">Gate History</span> · No gate records yet.
            </p>
        @else
            <p class="text-section-label uppercase text-text-secondary">Gate History ({{ $gateHistory->count() }})</p>
            <div class="mt-xs overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-metadata">
                    <thead>
                        <tr class="border-b border-border text-text-secondary">
                            <th class="py-xs pr-md font-semibold">Date</th>
                            <th class="py-xs pr-md font-semibold">PDL</th>
                            <th class="py-xs pr-md font-semibold">In</th>
                            <th class="py-xs pr-md font-semibold">Out</th>
                            <th class="py-xs pr-md font-semibold">ID Held</th>
                            <th class="py-xs pr-md font-semibold">Method</th>
                            <th class="py-xs font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($gateHistory as $entry)
                            @php
                                $in = $entry->check_in_time ? \Illuminate\Support\Carbon::parse($entry->check_in_time) : null;
                                $out = $entry->check_out_time ? \Illuminate\Support\Carbon::parse($entry->check_out_time) : null;
                            @endphp
                            <tr>
                                <td class="whitespace-nowrap py-xs pr-md font-semibold">{{ $in?->format('M d, Y') ?? '—' }}</td>
                                <td class="py-xs pr-md">{{ $entry->visitRequest?->pdl?->full_name ?? '—' }}</td>
                                <td class="py-xs pr-md">
                                    {{ $in?->format('H:i') ?? '—' }}
                                    @if ($entry->checkInOfficer)
                                        <span class="block text-text-secondary">by {{ $entry->checkInOfficer->full_name }}</span>
                                    @endif
                                </td>
                                <td class="py-xs pr-md">
                                    {{ $out?->format('H:i') ?? '—' }}
                                    @if ($in && $out)
                                        <span class="block text-text-secondary">{{ $in->diffForHumans($out, ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]) }}</span>
                                    @endif
                                </td>
                                <td class="py-xs pr-md">{{ $entry->id_surrendered_type ? (new \App\Models\VisitorId(['id_type' => $entry->id_surrendered_type]))->typeLabel() : '—' }}</td>
                                <td class="py-xs pr-md">{{ $entry->verification_method === 'qr_scan' ? 'QR Scan' : 'Manual' }}</td>
                                <td class="py-xs">@include('admin.partials.status-chip', ['status' => $entry->status])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Flag history (resolved ones too; active ones are also shown at the top) --}}
    @if ($visitor->flags->isNotEmpty())
        <div class="cc-card">
            <p class="text-section-label uppercase text-text-secondary">Flag History ({{ $visitor->flags->count() }})</p>
            <ul class="mt-xs divide-y divide-border">
                @foreach ($visitor->flags as $flag)
                    <li class="flex flex-wrap items-start justify-between gap-sm py-sm text-metadata">
                        <div>
                            <p class="font-semibold text-text-primary">{{ ucwords(str_replace('_', ' ', $flag->flag_type)) }}</p>
                            <p class="text-text-secondary">{{ $flag->description }}</p>
                            <p class="text-text-secondary">
                                Flagged {{ $flag->created_at?->format('M d, Y') ?? '—' }}
                                @if ($flag->resolved_at)
                                    · Resolved {{ $flag->resolved_at->format('M d, Y') }}
                                @endif
                            </p>
                        </div>
                        @include('admin.partials.status-chip', ['status' => $flag->status])
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
