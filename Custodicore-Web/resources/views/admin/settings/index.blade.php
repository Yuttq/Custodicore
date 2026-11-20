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
            Visitors see this in the app's Help Center, with a button to call. Leave Phone Number empty to hide it.
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
                           value="{{ old('phone', $facilityContact['phone']) }}" class="cc-input" placeholder="e.g. 0917 123 4567 or (032) 123-4567" />
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
        <p class="text-card-title">Visiting Rules</p>
        <p class="text-metadata text-text-secondary">
            Counts all PDLs together, Monday–Sunday. A whole-day visit counts as one.
            A change applies to new bookings right away; visits already booked are not changed.
        </p>

        <form method="POST" action="{{ route('admin.settings.visiting-rules') }}" class="mt-md"
              data-password-confirm="Change the weekly visit limit? Enter your password to confirm.">
            @csrf
            @method('PUT')
            <div class="grid grid-cols-1 gap-sm sm:grid-cols-3">
                <div>
                    <label class="cc-label" for="visiting-max-per-week">Visits per Visitor per Week
                        <span class="normal-case">({{ \App\Models\SystemSetting::MAX_PER_WEEK_MIN }}–{{ \App\Models\SystemSetting::MAX_PER_WEEK_MAX }})</span></label>
                    <input id="visiting-max-per-week" type="number" name="max_per_week" required
                           min="{{ \App\Models\SystemSetting::MAX_PER_WEEK_MIN }}" max="{{ \App\Models\SystemSetting::MAX_PER_WEEK_MAX }}" step="1"
                           value="{{ old('max_per_week', $maxPerWeek) }}" class="cc-input" />
                    @error('max_per_week') <p class="mt-xs text-metadata text-danger">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="mt-md flex justify-end">
                <button type="submit" class="cc-btn-primary">Save Rules</button>
            </div>
        </form>
    </div>

    <div class="cc-card">
        <p class="text-card-title">Role &amp; Module Access</p>
        <p class="text-metadata text-text-secondary">What each role can access in the system.</p>

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
