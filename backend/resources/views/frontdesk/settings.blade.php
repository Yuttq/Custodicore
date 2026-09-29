@extends('layouts.frontdesk')

@section('title', 'Settings')

@section('content')
    <div class="cc-card">
        <p class="text-card-title">Gate Settings</p>
        <p class="text-metadata text-text-secondary">Read-only — set by the Warden on the admin dashboard</p>

        <div class="mt-md overflow-x-auto">
            <table class="w-full text-left text-body">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md">Key</th>
                        <th class="py-sm pr-md">Value</th>
                        <th class="py-sm pr-md">Description</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($settings as $setting)
                        <tr>
                            <td class="py-sm pr-md font-mono text-text-secondary">{{ $setting['key'] }}</td>
                            <td class="py-sm pr-md"><span class="cc-chip cc-chip-info">{{ $setting['value'] }}</span></td>
                            <td class="py-sm pr-md text-text-secondary">{{ $setting['description'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
