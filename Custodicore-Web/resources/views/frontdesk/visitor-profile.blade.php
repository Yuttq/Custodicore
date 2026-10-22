@extends('layouts.frontdesk')

@section('title', 'Visitor Profile')

@php
    // Only flags still in force (not resolved, not past their time limit).
    $activeFlags = $visitor->flags->filter->isActive();
    $genderLabel = $visitor->gender ? ucfirst($visitor->gender) : '—';
    $show = fn ($value) => filled($value) ? $value : '—';
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

    {{-- Header --}}
    <div class="cc-card">
        <div class="flex flex-col gap-md sm:flex-row sm:items-center">
            {{-- The visitor's own photo (from the app) so the gate can match the face; initials otherwise. --}}
            @if ($photoUrl = $visitor->photoUrl())
                <img src="{{ $photoUrl }}" alt="Photo of {{ $visitor->full_name }}" class="h-16 w-16 shrink-0 rounded-full border border-border object-cover">
            @else
                @include('admin.partials.avatar', ['name' => $visitor->full_name, 'size' => 'h-16 w-16'])
            @endif

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-sm">
                    <h2 class="text-page-title">{{ $visitor->full_name }}</h2>
                    @include('admin.partials.status-chip', ['status' => $visitor->verification_status])
                    @if ($activeFlags->isNotEmpty())
                        <span class="cc-chip cc-chip-danger">{{ $activeFlags->count() }} active {{ \Illuminate\Support\Str::plural('flag', $activeFlags->count()) }}</span>
                    @endif
                </div>
                <p class="mt-xs text-metadata text-text-secondary">
                    Visitor ID: <span class="font-semibold text-text-primary">{{ $visitor->visitor_id }}</span>
                    · Registered {{ $visitor->created_at?->format('M d, Y') ?? '—' }}
                    @if ($visitor->verified_at)
                        · Verified {{ $visitor->verified_at->format('M d, Y') }}
                    @endif
                </p>
            </div>
        </div>

        <dl class="mt-lg grid grid-cols-2 gap-md border-t border-border pt-md sm:grid-cols-4">
            <div>
                <dt class="text-section-label text-text-secondary">COMPLETED VISITS</dt>
                <dd class="mt-xs text-lg font-semibold">{{ $stats['totalVisits'] }}</dd>
            </div>
            <div>
                <dt class="text-section-label text-text-secondary">GATE ENTRIES</dt>
                <dd class="mt-xs text-lg font-semibold">{{ $stats['gateEntries'] }}</dd>
            </div>
            <div>
                <dt class="text-section-label text-text-secondary">NO-SHOWS</dt>
                <dd class="mt-xs text-lg font-semibold {{ $stats['noShows'] ? 'text-danger' : '' }}">{{ $stats['noShows'] }}</dd>
            </div>
            <div>
                <dt class="text-section-label text-text-secondary">LAST VISIT</dt>
                <dd class="mt-xs text-lg font-semibold">{{ $stats['lastVisit'] ? \Illuminate\Support\Carbon::parse($stats['lastVisit'])->format('M d, Y') : '—' }}</dd>
            </div>
        </dl>
    </div>

    {{-- Today's visit --}}
    <div class="cc-card">
        <div class="flex flex-wrap items-center justify-between gap-sm">
            <div>
                <p class="text-card-title">Today's Visit</p>
                <p class="text-metadata text-text-secondary">{{ now()->format('l, M d, Y') }}</p>
            </div>
            {{-- Only when there is something to do at the gate: arriving, or inside. --}}
            @if ($todayVisits->contains(fn ($today) => in_array($today['gate']['status'], ['awaiting_arrival', 'checked_in'], true)))
                <a href="{{ route('frontdesk.checkin-checkout') }}" class="cc-btn-primary">Go to Check-In / Check-Out</a>
            @endif
        </div>

        <div class="mt-md space-y-sm">
            @forelse ($todayVisits as $today)
                <div class="flex flex-col gap-sm rounded-lg border border-border bg-background p-md sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-body font-semibold">
                            Visiting {{ $today['visit']->pdl?->full_name ?? '—' }}
                            <span class="font-normal text-text-secondary">({{ $today['visit']->pdl?->pdl_number ?? '—' }})</span>
                        </p>
                        <p class="text-metadata text-text-secondary">{{ $today['sessions'] }}</p>
                    </div>
                    <div class="flex flex-col items-start gap-xs sm:items-end">
                        @include('admin.partials.status-chip', ['status' => $today['gate']['status']])
                        <span class="text-metadata text-text-secondary">{{ $today['gate']['label'] }}</span>
                    </div>
                </div>
            @empty
                <p class="text-metadata text-text-secondary">No visit scheduled for today.</p>
            @endforelse
        </div>
    </div>

    <div class="grid grid-cols-1 gap-lg lg:grid-cols-2">
        {{-- Personal information --}}
        <div class="cc-card">
            <p class="text-card-title">Personal Information</p>

            <dl class="mt-md grid grid-cols-1 gap-md sm:grid-cols-2">
                <div>
                    <dt class="text-section-label text-text-secondary">CONTACT NUMBER</dt>
                    <dd class="mt-xs text-body font-semibold">{{ $show($visitor->contact_number) }}</dd>
                </div>
                <div>
                    <dt class="text-section-label text-text-secondary">EMAIL</dt>
                    <dd class="mt-xs break-all text-body font-semibold">{{ $show($visitor->account?->email) }}</dd>
                </div>
                <div>
                    <dt class="text-section-label text-text-secondary">DATE OF BIRTH</dt>
                    <dd class="mt-xs text-body font-semibold">
                        {{ $visitor->date_of_birth?->format('M d, Y') ?? '—' }}
                        @if ($age !== null)
                            <span class="font-normal text-text-secondary">({{ $age }} years old)</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-section-label text-text-secondary">SEX</dt>
                    <dd class="mt-xs text-body font-semibold">{{ $genderLabel }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-section-label text-text-secondary">ADDRESS</dt>
                    <dd class="mt-xs text-body font-semibold">{{ $show($visitor->address) }}</dd>
                </div>
                <div>
                    <dt class="text-section-label text-text-secondary">EMERGENCY CONTACT</dt>
                    <dd class="mt-xs text-body font-semibold">{{ $show($visitor->emergency_contact_name) }}</dd>
                </div>
                <div>
                    <dt class="text-section-label text-text-secondary">EMERGENCY NUMBER</dt>
                    <dd class="mt-xs text-body font-semibold">{{ $show($visitor->emergency_contact_number) }}</dd>
                </div>
            </dl>
        </div>

        {{-- IDs on file --}}
        <div class="cc-card">
            <p class="text-card-title">IDs on File</p>
            <p class="text-metadata text-text-secondary">Compare these with the ID the visitor presents at the gate.</p>

            <ul class="mt-md divide-y divide-border">
                @forelse ($visitor->idDocuments as $doc)
                    @php
                        $hasPhoto = $doc->file_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($doc->file_path);
                    @endphp
                    <li class="flex flex-wrap items-center justify-between gap-sm py-sm">
                        <div>
                            <p class="text-body font-semibold">{{ $doc->typeLabel() }}</p>
                            <p class="text-metadata text-text-secondary">
                                No. <span class="font-semibold text-text-primary">{{ $doc->id_number }}</span>
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
    </div>

    <div class="grid grid-cols-1 gap-lg lg:grid-cols-2">
        {{-- PDL relationships --}}
        <div class="cc-card">
            <p class="text-card-title">PDLs This Visitor May Visit</p>
            <p class="text-metadata text-text-secondary">Only verified relationships can be scheduled.</p>

            <ul class="mt-md divide-y divide-border">
                @forelse ($visitor->relationships as $rel)
                    <li class="flex flex-wrap items-center justify-between gap-sm py-sm">
                        <div>
                            <p class="text-body font-semibold">
                                {{ $rel->pdl?->full_name ?? '—' }}
                                <span class="font-normal text-text-secondary">({{ $rel->pdl?->pdl_number ?? '—' }})</span>
                            </p>
                            <p class="text-metadata text-text-secondary">
                                {{ $rel->relationshipLabel() }}
                                @if ($rel->pdl?->cell_block)
                                    · {{ $rel->pdl->cell_block }}
                                @endif
                            </p>
                        </div>
                        @include('admin.partials.status-chip', ['status' => $rel->verification_status ?? 'pending'])
                    </li>
                @empty
                    <li class="py-sm text-metadata text-text-secondary">No PDL relationships on record.</li>
                @endforelse
            </ul>
        </div>

        {{-- Upcoming visits --}}
        <div class="cc-card">
            <p class="text-card-title">Upcoming Visits</p>
            <p class="text-metadata text-text-secondary">Scheduled after today.</p>

            <ul class="mt-md divide-y divide-border">
                @forelse ($upcomingVisits as $upcoming)
                    <li class="flex flex-wrap items-center justify-between gap-sm py-sm">
                        <div>
                            <p class="text-body font-semibold">{{ $upcoming['visit']->schedule?->schedule_date?->format('D, M d, Y') ?? '—' }}</p>
                            <p class="text-metadata text-text-secondary">
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

    {{-- Gate history --}}
    <div class="cc-card">
        <p class="text-card-title">Gate History</p>
        <p class="text-metadata text-text-secondary">Most recent {{ $gateHistory->count() }} check-ins and check-outs.</p>

        <div class="mt-md overflow-x-auto">
            <table class="w-full min-w-[720px] text-left">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md font-semibold">DATE</th>
                        <th class="py-sm pr-md font-semibold">PDL</th>
                        <th class="py-sm pr-md font-semibold">IN</th>
                        <th class="py-sm pr-md font-semibold">OUT</th>
                        <th class="py-sm pr-md font-semibold">ID HELD</th>
                        <th class="py-sm pr-md font-semibold">METHOD</th>
                        <th class="py-sm font-semibold">STATUS</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($gateHistory as $entry)
                        @php
                            $in = $entry->check_in_time ? \Illuminate\Support\Carbon::parse($entry->check_in_time) : null;
                            $out = $entry->check_out_time ? \Illuminate\Support\Carbon::parse($entry->check_out_time) : null;
                        @endphp
                        <tr class="text-body">
                            <td class="py-sm pr-md font-semibold">{{ $in?->format('M d, Y') ?? '—' }}</td>
                            <td class="py-sm pr-md">{{ $entry->visitRequest?->pdl?->full_name ?? '—' }}</td>
                            <td class="py-sm pr-md">
                                {{ $in?->format('H:i') ?? '—' }}
                                @if ($entry->checkInOfficer)
                                    <span class="block text-metadata text-text-secondary">by {{ $entry->checkInOfficer->full_name }}</span>
                                @endif
                            </td>
                            <td class="py-sm pr-md">
                                {{ $out?->format('H:i') ?? '—' }}
                                @if ($in && $out)
                                    <span class="block text-metadata text-text-secondary">{{ $in->diffForHumans($out, ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]) }}</span>
                                @endif
                            </td>
                            <td class="py-sm pr-md">{{ $entry->id_surrendered_type ? (new \App\Models\VisitorId(['id_type' => $entry->id_surrendered_type]))->typeLabel() : '—' }}</td>
                            <td class="py-sm pr-md">{{ $entry->verification_method === 'qr_scan' ? 'QR Scan' : 'Manual' }}</td>
                            <td class="py-sm">@include('admin.partials.status-chip', ['status' => $entry->status])</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-md text-center text-metadata text-text-secondary">No gate records yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Flag history (resolved ones too; active ones are also shown at the top) --}}
    @if ($visitor->flags->isNotEmpty())
        <div class="cc-card">
            <p class="text-card-title">Flag History</p>
            <ul class="mt-md divide-y divide-border">
                @foreach ($visitor->flags as $flag)
                    <li class="flex flex-wrap items-start justify-between gap-sm py-sm">
                        <div>
                            <p class="text-body font-semibold">{{ ucwords(str_replace('_', ' ', $flag->flag_type)) }}</p>
                            <p class="text-metadata text-text-secondary">{{ $flag->description }}</p>
                            <p class="text-metadata text-text-secondary">
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
