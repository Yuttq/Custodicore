@extends('layouts.admin')

@section('title', 'Settings')

@section('content')
    <div class="grid grid-cols-1 gap-md sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($summary as $stat)
            @include('admin.partials.stat-card', $stat)
        @endforeach
    </div>

    <div class="cc-card">
        <p class="text-card-title">Facility Contact</p>
        <p class="text-metadata text-text-secondary">
            Shown to visitors in the app's Help Center, with a Call button. Leave the phone number empty to hide it.
        </p>

        <form method="POST" action="{{ route('admin.settings.facility-contact') }}" class="mt-md"
              data-password-confirm="Update the facility contact shown to visitors? Enter your password to confirm.">
            @csrf
            @method('PUT')
            <div class="grid grid-cols-1 gap-sm sm:grid-cols-3">
                <div>
                    <label class="cc-label" for="facility-contact-name">Name <span class="normal-case">(optional)</span></label>
                    <input id="facility-contact-name" type="text" name="name" maxlength="{{ \App\Support\FacilityContact::NAME_MAX }}"
                           value="{{ old('name', $facilityContact['name']) }}" class="cc-input" placeholder="e.g. Visiting Area Desk" />
                    @error('name') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="cc-label" for="facility-contact-phone">Phone Number</label>
                    <input id="facility-contact-phone" type="tel" name="phone" maxlength="20"
                           value="{{ old('phone', $facilityContact['phone']) }}" class="cc-input" placeholder="e.g. (032) 123-4567" />
                    @error('phone') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="cc-label" for="facility-contact-hours">Hours <span class="normal-case">(optional)</span></label>
                    <input id="facility-contact-hours" type="text" name="hours" maxlength="{{ \App\Support\FacilityContact::HOURS_MAX }}"
                           value="{{ old('hours', $facilityContact['hours']) }}" class="cc-input" placeholder="e.g. Mon–Fri, 8:00 AM – 5:00 PM" />
                    @error('hours') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="mt-md flex justify-end">
                <button type="submit" class="cc-btn-primary">Save Contact</button>
            </div>
        </form>
    </div>

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
                        <tr class="transition hover:bg-background">
                            <td class="py-sm pr-md text-text-secondary">{{ $setting['key'] }}</td>
                            <td class="py-sm pr-md">
                                <span class="cc-chip cc-chip-info">{{ $setting['value'] }}</span>
                            </td>
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
                <li class="flex items-start gap-sm border-b border-border pb-sm last:border-0 last:pb-0">
                    @include('admin.partials.avatar', ['name' => \App\Models\Role::label($role['role_name']), 'size' => 'h-9 w-9'])
                    <div>
                        <p class="text-body font-semibold">{{ \App\Models\Role::label($role['role_name']) }}</p>
                        <p class="text-metadata text-text-secondary">{{ $role['access'] }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
@endsection
