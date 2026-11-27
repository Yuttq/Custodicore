@extends('layouts.admin')

@section('title', $order === 'most' ? 'Most Visited PDLs' : 'PDLs With No Visits Yet')

@section('content')
    <div class="cc-card">
        <div class="mb-md flex flex-col gap-sm lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="text-card-title">{{ $order === 'most' ? 'Most Visited PDLs' : 'No Visits Yet' }}</p>
                <p class="text-metadata text-text-secondary">
                    {{ $order === 'most' ? 'PDLs who have received visits, most visits first' : 'PDLs in custody who have not received any visit' }}
                    · {{ $total }} {{ $total === 1 ? 'PDL' : 'PDLs' }}
                    @if ($search) · matching "{{ $search }}"@endif
                    @if (! $showAll && $total > 5) · <span class="font-semibold text-text-primary">showing the top 5</span>@endif
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-sm">
                {{-- Switch the order without losing the search. --}}
                <div class="flex rounded-button border border-border bg-white p-[3px]" role="group" aria-label="Sort">
                    <a href="{{ route('admin.pdls.visits', array_filter(['order' => 'most', 'q' => $search])) }}"
                       class="rounded-[6px] px-md py-[6px] text-body font-semibold {{ $order === 'most' ? 'bg-primary-navy text-white' : 'text-text-secondary hover:text-primary-navy' }}"
                       @if ($order === 'most') aria-current="page" @endif>Most visited</a>
                    <a href="{{ route('admin.pdls.visits', array_filter(['order' => 'least', 'q' => $search])) }}"
                       class="rounded-[6px] px-md py-[6px] text-body font-semibold {{ $order === 'least' ? 'bg-primary-navy text-white' : 'text-text-secondary hover:text-primary-navy' }}"
                       @if ($order === 'least') aria-current="page" @endif>No visits yet</a>
                </div>

                <form method="GET" action="{{ route('admin.pdls.visits') }}" class="flex items-center gap-sm">
                    <input type="hidden" name="order" value="{{ $order }}" />
                    <input type="search" name="q" value="{{ $search }}" maxlength="100"
                           placeholder="Search name, PDL # or cell block…"
                           class="h-[38px] w-full rounded-button border border-border bg-white px-md text-body sm:w-64" />
                    <button type="submit" class="cc-btn-secondary shrink-0">Search</button>
                    @if ($search)
                        <a href="{{ route('admin.pdls.visits', ['order' => $order]) }}" class="cc-btn-secondary shrink-0">Clear</a>
                    @endif
                </form>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-body">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md">#</th>
                        <th class="py-sm pr-md">PDL</th>
                        <th class="py-sm pr-md">Cell Block</th>
                        <th class="py-sm pr-md">Visits</th>
                        <th class="py-sm pr-md">Last Visit</th>
                        <th class="py-sm pr-md">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($pdls as $i => $pdl)
                        <tr class="transition hover:bg-background">
                            <td class="py-sm pr-md text-text-secondary">{{ ($pdls->currentPage() - 1) * $pdls->perPage() + $loop->iteration }}</td>
                            <td class="py-sm pr-md">
                                <div class="flex items-center gap-sm">
                                    @include('admin.partials.avatar', ['name' => $pdl['full_name'], 'photo' => $pdl['photo'], 'size' => 'h-9 w-9'])
                                    <div>
                                        <a href="{{ route('admin.pdls.show', $pdl['id']) }}" class="font-semibold hover:underline">{{ $pdl['list_name'] }}</a>
                                        <p class="text-metadata text-text-secondary">{{ $pdl['pdl_number'] }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="py-sm pr-md text-text-secondary">{{ $pdl['cell_block'] ?: '—' }}</td>
                            <td class="py-sm pr-md">
                                @if ($pdl['visit_count'] > 0)
                                    <span class="cc-chip cc-chip-info">{{ $pdl['visit_count'] }}</span>
                                @else
                                    <span class="rounded-chip bg-[#FEF3C7] px-[10px] py-[3px] text-body font-semibold text-[#B45309]">0</span>
                                @endif
                            </td>
                            <td class="py-sm pr-md text-text-secondary">{{ $pdl['last_visit'] ?? 'No visit on record' }}</td>
                            <td class="py-sm pr-md">
                                <a href="{{ route('admin.pdls.show', $pdl['id']) }}" class="cc-btn-ghost">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-lg text-center text-text-secondary">
                                @if ($search)
                                    No PDL matches your search.
                                @elseif ($order === 'most')
                                    No PDL has received a visit yet.
                                @else
                                    Every PDL in custody has received at least one visit.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if (! $showAll && $total > 5)
            <div class="mt-md flex justify-center">
                <a href="{{ route('admin.pdls.visits', ['order' => $order, 'all' => 1]) }}" class="cc-btn-secondary">Show all {{ $total }} PDLs</a>
            </div>
        @else
            @include('admin.partials.pagination-footer', ['paginator' => $pdls, 'label' => 'PDLs'])
            @if ($showAll && ! $search && $total > 5)
                <p class="mt-sm text-center text-metadata">
                    <a href="{{ route('admin.pdls.visits', ['order' => $order]) }}" class="font-semibold text-primary-navy hover:underline">Show top 5 only</a>
                </p>
            @endif
        @endif

        <p class="mt-sm text-metadata text-text-secondary">
            <a href="{{ route('admin.dashboard') }}" class="font-semibold text-primary-navy hover:underline">&larr; Back to Dashboard</a>
        </p>
    </div>
@endsection
