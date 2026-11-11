@extends('layouts.frontdesk')

@section('title', 'Visitor Profile')

@php
    // Only flags still in force (not resolved, not past their time limit).
    $activeFlags = $visitor->flags->filter->isActive();
    $plural = fn (int $n, string $word) => $n . ' ' . \Illuminate\Support\Str::plural($word, $n);

    // Same look as the walk-in record: coloured dot + label.
    $tone = ['verified' => 'success', 'pending' => 'warning', 'rejected' => 'danger'][$visitor->verification_status] ?? 'neutral';
    $statusText = ['success' => 'text-success', 'warning' => 'text-warning', 'danger' => 'text-danger'][$tone] ?? 'text-text-secondary';
    $statusDot = ['success' => 'bg-success', 'warning' => 'bg-warning', 'danger' => 'bg-danger'][$tone] ?? 'bg-text-secondary';

    $contact = $visitor->contact_number && $visitor->contact_number !== 'N/A' ? $visitor->contact_number : '—';
    $emergency = trim(($visitor->emergency_contact_name ?? '')
        . ($visitor->emergency_contact_number ? ' (' . $visitor->emergency_contact_number . ')' : ''));
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

    {{-- Who they are: photo, name, status, then the details on record (as on the walk-in record). --}}
    <div class="cc-card">
        <div class="flex flex-wrap items-center gap-md">
            @if ($photoUrl = $visitor->photoUrl())
                {{-- Big enough to match the face at the gate; click for the full-size photo. --}}
                <button type="button" id="visitorPhotoOpen" title="Click to enlarge the photo"
                        class="group relative shrink-0 overflow-hidden rounded-xl border-2 border-border transition hover:border-primary-teal focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-teal focus-visible:ring-offset-2">
                    <img src="{{ $photoUrl }}" alt="Photo of {{ $visitor->full_name }}" class="h-28 w-28 object-cover">
                    <span class="absolute inset-x-0 bottom-0 bg-black/55 py-0.5 text-center text-[11px] font-semibold text-white opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100">Enlarge</span>
                </button>
            @else
                @include('admin.partials.avatar', ['name' => $visitor->full_name, 'size' => 'h-28 w-28'])
            @endif
            <div class="min-w-0">
                <p class="flex flex-wrap items-center gap-x-md gap-y-xs">
                    <span class="text-[22px] font-bold leading-tight text-primary-navy">{{ $visitor->full_name }}</span>
                    <span class="inline-flex items-center gap-xs text-body font-semibold {{ $statusText }}">
                        <span class="h-2.5 w-2.5 rounded-full {{ $statusDot }}" aria-hidden="true"></span>
                        {{ ucfirst($visitor->verification_status ?? '—') }}
                    </span>
                </p>
                <p class="text-metadata text-text-secondary">
                    Visitor ID <strong>{{ $visitor->visitor_id }}</strong>
                    · {{ $visitor->isWalkIn() ? 'Walk-in' : 'App user' }}
                    @if ($visitor->verified_at)
                        · Verified {{ $visitor->verified_at->format('M d, Y') }}
                    @else
                        · Registered {{ $visitor->created_at?->format('M d, Y') ?? '—' }}
                    @endif
                </p>
            </div>
        </div>

        <dl class="mt-md grid grid-cols-1 gap-md text-body sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="cc-label">Date of birth / age</dt><dd class="mt-xs font-semibold">{{ $visitor->date_of_birth?->format('M j, Y') ?? '—' }}@if ($age !== null) ({{ $age }})@endif</dd></div>
            <div><dt class="cc-label">Sex</dt><dd class="mt-xs font-semibold">{{ $visitor->gender ? ucfirst($visitor->gender) : '—' }}</dd></div>
            <div><dt class="cc-label">Contact number</dt><dd class="mt-xs font-semibold">{{ $contact }}</dd></div>
            <div><dt class="cc-label">Email</dt><dd class="mt-xs break-words font-semibold">{{ $visitor->account?->email ?: '—' }}</dd></div>
            <div class="sm:col-span-2"><dt class="cc-label">Address</dt><dd class="mt-xs font-semibold">{{ $visitor->address ?: '—' }}</dd></div>
            <div class="sm:col-span-2"><dt class="cc-label">Emergency contact</dt><dd class="mt-xs font-semibold">{{ $emergency ?: '—' }}</dd></div>
            <div><dt class="cc-label">Completed visits</dt><dd class="mt-xs font-semibold">{{ $stats['totalVisits'] }}</dd></div>
            <div><dt class="cc-label">Gate entries</dt><dd class="mt-xs font-semibold">{{ $stats['gateEntries'] }}</dd></div>
            <div><dt class="cc-label">No-shows</dt><dd class="mt-xs font-semibold {{ $stats['noShows'] ? 'text-danger' : '' }}">{{ $stats['noShows'] }}</dd></div>
            <div><dt class="cc-label">Last visit</dt><dd class="mt-xs font-semibold">{{ $stats['lastVisit'] ? \Illuminate\Support\Carbon::parse($stats['lastVisit'])->format('M j, Y') : '—' }}</dd></div>
        </dl>
    </div>

    {{-- Today's visit --}}
    <div class="cc-card">
        <div class="flex flex-wrap items-start justify-between gap-sm">
            <div>
                <p class="text-card-title">Today's Visit</p>
                <p class="text-metadata text-text-secondary">{{ now()->format('l, F j, Y') }}. Where the visitor is for today's visit.</p>
            </div>
            {{-- Only when there is something to do at the gate: arriving, or inside. --}}
            @if ($todayVisits->contains(fn ($today) => in_array($today['gate']['status'], ['awaiting_arrival', 'checked_in'], true)))
                <a href="{{ route('frontdesk.checkin-checkout') }}" class="cc-btn-primary">Go to Check-In / Check-Out</a>
            @endif
        </div>

        @if ($todayVisits->isEmpty())
            <p class="mt-md text-metadata text-text-secondary">No visit scheduled today.</p>
        @else
            <div class="mt-md divide-y divide-border">
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
        @endif
    </div>

    <div class="grid grid-cols-1 gap-lg lg:grid-cols-2">
        {{-- IDs on file: compare with the ID the visitor shows at the gate. --}}
        <div class="cc-card">
            <p class="text-card-title">IDs on File</p>
            <p class="text-metadata text-text-secondary">Compare these with the ID the visitor hands over at the gate.</p>
            <ul class="mt-md divide-y divide-border">
                @forelse ($visitor->idDocuments as $doc)
                    <li class="flex flex-wrap items-center justify-between gap-sm py-sm">
                        <div>
                            <p class="text-body font-semibold">{{ $doc->typeLabel() }}</p>
                            <p class="text-metadata text-text-secondary">
                                Added {{ $doc->uploaded_at ? \Illuminate\Support\Carbon::parse($doc->uploaded_at)->format('M d, Y') : '—' }}
                            </p>
                        </div>
                        <div class="flex items-center gap-sm">
                            @include('admin.partials.status-chip', ['status' => $doc->verification_status])
                            @if ($doc->fileDisk() !== null)
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
            <p class="text-card-title">PDLs This Visitor May Visit</p>
            <p class="text-metadata text-text-secondary">The PDLs linked to this visitor and whether Records verified each one.</p>
            <ul class="mt-md divide-y divide-border">
                @forelse ($visitor->relationships as $rel)
                    <li class="flex flex-wrap items-center justify-between gap-sm py-sm">
                        <div>
                            <p class="text-body font-semibold">
                                {{ $rel->pdl?->full_name ?? '—' }}
                                <span class="font-normal text-text-secondary">({{ $rel->pdl?->pdl_number ?? '—' }})</span>
                            </p>
                            <p class="text-metadata text-text-secondary">
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
    </div>

    {{-- Upcoming visits (after today) --}}
    <div class="cc-card">
        <p class="text-card-title">Upcoming Visits</p>
        <p class="text-metadata text-text-secondary">Visits booked after today.</p>
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

    {{-- Gate history --}}
    <div class="cc-card">
        <p class="text-card-title">Gate History @if ($gateHistory->isNotEmpty())<span class="text-metadata font-normal text-text-secondary">· {{ $plural($gateHistory->count(), 'record') }}</span>@endif</p>
        <p class="text-metadata text-text-secondary">Every check-in and check-out of this visitor at the gate.</p>

        @if ($gateHistory->isEmpty())
            <p class="mt-md text-metadata text-text-secondary">No gate records yet.</p>
        @else
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
                        @foreach ($gateHistory as $entry)
                            @php
                                $in = $entry->check_in_time ? \Illuminate\Support\Carbon::parse($entry->check_in_time) : null;
                                $out = $entry->check_out_time ? \Illuminate\Support\Carbon::parse($entry->check_out_time) : null;
                            @endphp
                            <tr class="text-body align-top">
                                <td class="whitespace-nowrap py-sm pr-md font-semibold">{{ $in?->format('M d, Y') ?? '—' }}</td>
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
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Flag history (resolved ones too; active ones are also shown at the top) --}}
    @if ($visitor->flags->isNotEmpty())
        <div class="cc-card">
            <p class="text-card-title">Flag History <span class="text-metadata font-normal text-text-secondary">· {{ $visitor->flags->count() }}</span></p>
            <p class="text-metadata text-text-secondary">Warnings on this visitor, including resolved ones.</p>
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

    @if ($photoUrl)
        {{-- Full-size photo, opened from the photo in the header. --}}
        <dialog id="visitorPhotoDialog" class="cc-modal max-w-xl" aria-label="Photo of {{ $visitor->full_name }}">
            <div class="flex items-center justify-between gap-sm">
                <p class="text-card-title">{{ $visitor->full_name }}</p>
                <button type="button" class="cc-modal-close" data-photo-close>Close</button>
            </div>
            <img src="{{ $photoUrl }}" alt="Photo of {{ $visitor->full_name }}" class="mt-md max-h-[70vh] w-full rounded-lg border border-border bg-background object-contain">
            <div class="mt-md flex justify-end gap-sm">
                <a href="{{ $photoUrl }}" target="_blank" rel="noopener" class="cc-btn-secondary">Open in new tab</a>
                <button type="button" class="cc-btn-primary" data-photo-close>Done</button>
            </div>
        </dialog>
    @endif
@endsection

@push('scripts')
<script>
    (() => {
        const dialog = document.getElementById('visitorPhotoDialog');
        if (!dialog) return;
        document.getElementById('visitorPhotoOpen')?.addEventListener('click', () => dialog.showModal());
        dialog.querySelectorAll('[data-photo-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
        // Clicking the dark area around the photo closes it too.
        dialog.addEventListener('click', e => { if (e.target === dialog) dialog.close(); });
    })();
</script>
@endpush
