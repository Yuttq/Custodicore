@extends('layouts.admin')

@section('title', 'Visitor Management')

@section('content')
    <div class="grid grid-cols-1 gap-md sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($summary as $stat)
            @include('admin.partials.stat-card', $stat)
        @endforeach
    </div>

    <div class="cc-card">
        <div class="mb-md flex flex-col gap-sm lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="text-card-title">Registered Visitors</p>
                <p class="text-metadata text-text-secondary">
                    @if ($search)
                        {{ count($visitors) }} of {{ $totalVisitors }} visitors match "{{ $search }}"
                    @else
                        {{ count($visitors) }} visitors · read-only
                    @endif
                </p>
            </div>
            {{-- Working search: visitor name, contact #, email, or the related PDL's name / number. --}}
            <form method="GET" action="{{ route('admin.visitors.index') }}" class="flex items-center gap-sm">
                <input type="search" name="q" value="{{ $search }}" maxlength="100"
                       placeholder="Search visitor, contact #, email or PDL…"
                       class="h-[38px] w-full rounded-button border border-border bg-white px-md text-body sm:w-72" />
                <button type="submit" class="cc-btn-secondary shrink-0">Search</button>
                @if ($search)
                    <a href="{{ route('admin.visitors.index') }}" class="cc-btn-secondary shrink-0">Clear</a>
                @endif
            </form>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-body">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md">Name</th>
                        <th class="py-sm pr-md">Contact</th>
                        <th class="py-sm pr-md">Related PDL</th>
                        <th class="py-sm pr-md">PDL #</th>
                        <th class="py-sm pr-md">Relationship</th>
                        <th class="py-sm pr-md">Verification</th>
                        <th class="py-sm pr-md">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($visitors as $visitor)
                        <tr class="transition hover:bg-background">
                            <td class="py-sm pr-md">
                                <div class="flex items-center gap-sm">
                                    @include('admin.partials.avatar', ['name' => $visitor['full_name'], 'size' => 'h-8 w-8'])
                                    <a href="{{ route('admin.visitors.show', $visitor['id']) }}" class="font-semibold hover:underline">{{ $visitor['full_name'] }}</a>
                                </div>
                            </td>
                            <td class="py-sm pr-md text-text-secondary">{{ $visitor['contact_number'] }}</td>
                            <td class="py-sm pr-md">
                                {{ $visitor['related_pdl_name'] }}
                                @if ($visitor['more_pdls'] > 0)
                                    <span class="text-metadata text-text-secondary">+{{ $visitor['more_pdls'] }} more</span>
                                @endif
                            </td>
                            <td class="py-sm pr-md text-text-secondary">{{ $visitor['related_pdl_number'] }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ ucwords(str_replace('_', ' ', $visitor['relationship_type'])) }}</td>
                            <td class="py-sm pr-md">@include('admin.partials.status-chip', ['status' => $visitor['verification_status']])</td>
                            <td class="py-sm pr-md">
                                <a href="{{ route('admin.visitors.show', $visitor['id']) }}" class="cc-btn-ghost">View</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-lg text-center text-text-secondary">
                                {{ $search ? 'No visitor matches your search.' : 'No visitors registered yet.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('admin.partials.pagination-footer', ['total' => count($visitors), 'label' => 'visitors'])
    </div>
@endsection
