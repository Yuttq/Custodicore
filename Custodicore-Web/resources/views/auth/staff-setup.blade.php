@extends('layouts.auth')

{{-- Public page opened from the officer's inbox: no login needed. --}}
@section('title', 'Set up your account')
@section('hero_title', 'Welcome to the team.')
@section('hero_text', 'Your Admin created this account for you. Choose a username and a password only you know, and you are ready to sign in.')
@section('hero_note', 'This setup link works once and expires after it is used.')
{{-- Public email-link page: no BJMP seal (StaffSetupLinkDomainTest); the URL carries the setup token. --}}
@section('no_seal', '1')
@section('no_referrer', '1')
@section('no_flash', '1')

@section('content')
    @if ($state === 'form')
        <div class="au-head">
            <p class="au-eyebrow">First-time setup</p>
            <h2 class="au-title">Set up your staff account</h2>
            <p class="au-lead">Choose your own password. Nobody else, including the Admin, will ever see it. This link works once and is valid for {{ $hours }} hours from when it was sent.</p>
        </div>

        @php
            $initials = collect(preg_split('/\s+/', trim($account->displayName())))
                ->filter()->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->take(2)->implode('');
        @endphp
        <div class="au-who">
            <div class="au-avatar" aria-hidden="true">{{ $initials }}</div>
            <div style="min-width: 0; flex: 1;">
                <p class="n">{{ $account->displayName() }}</p>
                <p class="e">{{ $account->email }}</p>
            </div>
            <span class="au-chip">{{ \App\Models\Role::label($account->role?->role_name) }}</span>
        </div>

        @if ($errors->any())
            <div class="au-alert error" role="alert">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 7v6M12 17h.01"/></svg>
                <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ route('staff-setup.submit') }}" class="au-form" autocomplete="off">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div class="au-grid2">
                <div class="au-field">
                    <label for="username" class="au-label">Username</label>
                    <input id="username" name="username" type="text" required minlength="4" maxlength="30"
                           value="{{ old('username', $account->username) }}" autocapitalize="none" spellcheck="false"
                           class="au-input {{ $errors->has('username') ? 'invalid' : '' }}">
                    <p class="au-hint">Lowercase letters, numbers, . - _</p>
                </div>
                <div class="au-field">
                    <label for="contact_number" class="au-label">Mobile number</label>
                    <input id="contact_number" name="contact_number" type="tel" inputmode="tel" required maxlength="20"
                           value="{{ old('contact_number', $account->staffProfile?->contact_number) }}" placeholder="0917 123 4567"
                           class="au-input {{ $errors->has('contact_number') ? 'invalid' : '' }}">
                    <p class="au-hint">Check that it is correct.</p>
                </div>
            </div>
            <p class="au-hint" style="margin-top: -8px;">You can sign in with this username or with {{ $account->email }}. The username must be unique.</p>

            <div class="au-field">
                <label for="password" class="au-label">New password</label>
                <div class="au-pw">
                    <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password"
                           class="au-input {{ $errors->has('password') ? 'invalid' : '' }}">
                    <button type="button" class="au-eye" data-target="password" aria-label="Show password" aria-pressed="false" title="Show password">@include('auth.partials.eye-icons')</button>
                </div>
                <p class="au-hint">At least 10 characters with upper and lower case letters, a number and a symbol.</p>
            </div>

            <div class="au-field">
                <label for="password_confirmation" class="au-label">Confirm password</label>
                <div class="au-pw">
                    <input id="password_confirmation" name="password_confirmation" type="password" required minlength="10" autocomplete="new-password" class="au-input">
                    <button type="button" class="au-eye" data-target="password_confirmation" aria-label="Show password" aria-pressed="false" title="Show password">@include('auth.partials.eye-icons')</button>
                </div>
            </div>

            <button type="submit" class="au-btn">Save and activate my account</button>
        </form>

    @elseif ($state === 'expired')
        <div class="au-state">
            <div class="au-state-icon warn"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div>
            <h2 class="au-title">This link has expired</h2>
            <p>For your security, setup links expire. Ask the Admin to send you a new one.</p>
        </div>

    @else
        <div class="au-state">
            <div class="au-state-icon err"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></div>
            <h2 class="au-title">This link is not valid</h2>
            <p>It may already have been used, or replaced by a newer link. If you have not finished setting up, ask the Admin to send you a new one. If you already did, <a href="{{ route('login') }}">sign in here</a>.</p>
        </div>
    @endif
@endsection
