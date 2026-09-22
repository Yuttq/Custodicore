@extends('layouts.admin')

@section('title', 'Settings')
@section('subtitle', 'System configuration and the role → module access matrix')

@section('content')
    <div class="cc-card">
        <p class="text-card-title">System Settings</p>

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
                            <td class="py-sm pr-md font-semibold">{{ $setting['value'] }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ $setting['description'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="cc-card">
        <p class="text-card-title">Role &amp; Module Access</p>
        <p class="text-metadata text-text-secondary">From the approved CustodiCore brief's "Can Access" lists</p>

        <ul class="mt-md space-y-sm">
            @foreach ($roles as $role)
                <li class="border-b border-border pb-sm last:border-0 last:pb-0">
                    <p class="text-body font-semibold">{{ $role['role_name'] }}</p>
                    <p class="text-metadata text-text-secondary">{{ $role['access'] }}</p>
                </li>
            @endforeach
        </ul>
    </div>
@endsection
