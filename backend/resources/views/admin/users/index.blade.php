@extends('layouts.admin')

@section('title', 'User Management')
@section('subtitle', 'Module 1.1 — staff accounts and roles (System Administrator/Warden, Record Officer, Front Desk Officer)')

@section('content')
    <div class="cc-card">
        <div class="mb-md flex items-center justify-between">
            <div>
                <p class="text-card-title">Staff Accounts</p>
                <p class="text-metadata text-text-secondary">{{ count($accounts) }} accounts</p>
            </div>
            <button type="button" class="cc-btn-primary">+ New Account</button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-body">
                <thead>
                    <tr class="border-b border-border text-section-label text-text-secondary">
                        <th class="py-sm pr-md">Employee #</th>
                        <th class="py-sm pr-md">Name</th>
                        <th class="py-sm pr-md">Role</th>
                        <th class="py-sm pr-md">Email</th>
                        <th class="py-sm pr-md">Last Login</th>
                        <th class="py-sm pr-md">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($accounts as $account)
                        <tr>
                            <td class="py-sm pr-md text-text-secondary">{{ $account['employee_number'] }}</td>
                            <td class="py-sm pr-md font-semibold">{{ $account['full_name'] }}</td>
                            <td class="py-sm pr-md">{{ $account['role_name'] }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ $account['email'] }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ $account['last_login_at'] }}</td>
                            <td class="py-sm pr-md">@include('admin.partials.status-chip', ['status' => $account['status']])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
