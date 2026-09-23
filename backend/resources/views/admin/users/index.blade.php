@extends('layouts.admin')

@section('title', 'User Management')

@section('content')
    <div class="grid grid-cols-1 gap-md sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($summary as $stat)
            @include('admin.partials.stat-card', $stat)
        @endforeach
    </div>

    <div class="cc-card">
        <div class="mb-md flex flex-col gap-sm lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="text-card-title">Staff Accounts</p>
                <p class="text-metadata text-text-secondary">{{ count($accounts) }} accounts</p>
            </div>
            <div class="flex items-center gap-sm">
                @include('admin.partials.search-box', ['placeholder' => 'Search by name or email…'])
                <button type="button" class="cc-btn-primary shrink-0" onclick="document.getElementById('new-account-modal').showModal()">
                    @include('admin.partials.icon', ['name' => 'plus', 'class' => 'h-4 w-4'])
                    Register Officer
                </button>
            </div>
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
                        <th class="py-sm pr-md">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($accounts as $account)
                        <tr class="transition hover:bg-background">
                            <td class="py-sm pr-md text-text-secondary">{{ $account['employee_number'] }}</td>
                            <td class="py-sm pr-md">
                                <div class="flex items-center gap-sm">
                                    @include('admin.partials.avatar', ['name' => $account['full_name'], 'size' => 'h-8 w-8'])
                                    <span class="font-semibold">{{ $account['full_name'] }}</span>
                                </div>
                            </td>
                            <td class="py-sm pr-md">{{ $account['role_name'] }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ $account['email'] }}</td>
                            <td class="py-sm pr-md text-text-secondary">{{ $account['last_login_at'] }}</td>
                            <td class="py-sm pr-md">@include('admin.partials.status-chip', ['status' => $account['status']])</td>
                            <td class="py-sm pr-md">
                                <div class="flex items-center gap-xs">
                                    <button type="button" class="cc-btn-ghost" onclick="document.getElementById('edit-account-modal-{{ $account['id'] }}').showModal()">
                                        Edit
                                    </button>
                                    <form method="POST" action="{{ route('admin.users.toggle-status', $account['id']) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="cc-btn-ghost">
                                            {{ $account['status'] === 'active' ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @include('admin.partials.pagination-footer', ['total' => count($accounts), 'label' => 'accounts'])
    </div>

    {{-- "Register Officer" — the admin's only way to create data on this dashboard. --}}
    <dialog id="new-account-modal" class="cc-modal">
        <form method="POST" action="{{ route('admin.users.store') }}">
            @csrf
            <div class="mb-md flex items-center justify-between">
                <p class="text-card-title">Register BJMP Officer</p>
                <button type="button" class="cc-modal-close" onclick="document.getElementById('new-account-modal').close()">
                    @include('admin.partials.icon', ['name' => 'x', 'class' => 'h-4 w-4'])
                </button>
            </div>

            @if ($errors->any() && ! old('_edit_id'))
                <p class="mb-md text-metadata text-danger">{{ $errors->first() }}</p>
            @endif

            <div class="space-y-sm">
                <div>
                    <label class="cc-label">Full Name</label>
                    <input type="text" name="full_name" value="{{ old('_edit_id') ? '' : old('full_name') }}" required class="cc-input" placeholder="e.g. Juan D. Dela Cruz" />
                </div>
                <div>
                    <label class="cc-label">Role</label>
                    <select name="role_name" required class="cc-input">
                        <option value="" selected disabled>Select a role…</option>
                        <option value="System Administrator/Warden" @selected(old('role_name') === 'System Administrator/Warden')>System Administrator/Warden</option>
                        <option value="Record Officer" @selected(old('role_name') === 'Record Officer')>Record Officer</option>
                        <option value="Front Desk Officer" @selected(old('role_name') === 'Front Desk Officer')>Front Desk Officer</option>
                    </select>
                </div>
                <div>
                    <label class="cc-label">Email</label>
                    <input type="email" name="email" value="{{ old('_edit_id') ? '' : old('email') }}" required class="cc-input" placeholder="name@bjmp.gov.ph" />
                </div>
            </div>

            <div class="mt-lg flex justify-end gap-sm">
                <button type="button" class="cc-btn-secondary" onclick="document.getElementById('new-account-modal').close()">Cancel</button>
                <button type="submit" class="cc-btn-primary">Create Account</button>
            </div>
        </form>
    </dialog>

    {{-- "Edit" — the admin's only way to change existing data, when the
         officer whose account it is has asked for a correction. One modal
         per row, pre-filled with that account's current details. --}}
    @foreach ($accounts as $account)
        <dialog id="edit-account-modal-{{ $account['id'] }}" class="cc-modal">
            <form method="POST" action="{{ route('admin.users.update', $account['id']) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="_edit_id" value="{{ $account['id'] }}" />

                <div class="mb-md flex items-center justify-between">
                    <p class="text-card-title">Update Officer Details</p>
                    <button type="button" class="cc-modal-close" onclick="document.getElementById('edit-account-modal-{{ $account['id'] }}').close()">
                        @include('admin.partials.icon', ['name' => 'x', 'class' => 'h-4 w-4'])
                    </button>
                </div>

                @if ($errors->any() && old('_edit_id') == $account['id'])
                    <p class="mb-md text-metadata text-danger">{{ $errors->first() }}</p>
                @endif

                <div class="space-y-sm">
                    <div>
                        <label class="cc-label">Full Name</label>
                        <input type="text" name="full_name"
                               value="{{ old('_edit_id') == $account['id'] ? old('full_name') : $account['full_name'] }}"
                               required class="cc-input" />
                    </div>
                    <div>
                        <label class="cc-label">Role</label>
                        @php $currentRole = old('_edit_id') == $account['id'] ? old('role_name') : $account['role_name']; @endphp
                        <select name="role_name" required class="cc-input">
                            <option value="System Administrator/Warden" @selected($currentRole === 'System Administrator/Warden')>System Administrator/Warden</option>
                            <option value="Record Officer" @selected($currentRole === 'Record Officer')>Record Officer</option>
                            <option value="Front Desk Officer" @selected($currentRole === 'Front Desk Officer')>Front Desk Officer</option>
                        </select>
                    </div>
                    <div>
                        <label class="cc-label">Email</label>
                        <input type="email" name="email"
                               value="{{ old('_edit_id') == $account['id'] ? old('email') : $account['email'] }}"
                               required class="cc-input" />
                    </div>
                </div>

                <div class="mt-lg flex justify-end gap-sm">
                    <button type="button" class="cc-btn-secondary" onclick="document.getElementById('edit-account-modal-{{ $account['id'] }}').close()">Cancel</button>
                    <button type="submit" class="cc-btn-primary">Save Changes</button>
                </div>
            </form>
        </dialog>
    @endforeach

    @if ($errors->any())
        @if (old('_edit_id'))
            <script>document.getElementById('edit-account-modal-{{ old('_edit_id') }}').showModal();</script>
        @else
            <script>document.getElementById('new-account-modal').showModal();</script>
        @endif
    @endif
@endsection
