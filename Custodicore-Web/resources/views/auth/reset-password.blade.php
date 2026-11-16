@extends('layouts.auth')

{{-- Public page opened from the visitor's or officer's inbox: no login needed. --}}
@php $isStaff = $isStaff ?? false; @endphp

@section('title', 'Reset password')
@section('hero_title', 'Choose a password only you know.')
@section('hero_text', 'This link came from your email. Once your new password is saved, every device that was signed in to your account is signed out.')
@section('hero_note', 'This reset link works once and only from the newest email.')
{{-- Public email-link page: no BJMP seal; the URL carries the reset token. --}}
@section('no_seal', '1')
@section('no_referrer', '1')
@section('no_flash', '1')

@push('head')
    <style>
        /* Classes the shared "Open the CustodiCore app" partial expects. */
        .btn { display: inline-flex; align-items: center; justify-content: center; height: 50px; padding: 0 24px; border-radius: 12px; background: var(--green); color: #fff; font-size: 16px; font-weight: 700; text-decoration: none; }
        .btn:hover { background: var(--green-dark); color: #fff; }
        .muted { margin: 0; font-size: 14px; color: var(--muted); }
        .notice { margin: 0; font-size: 14px; color: var(--text); background: #FEF3C7; border: 1px solid #F59E0B; border-radius: 10px; padding: 10px 12px; }
    </style>
@endpush

@section('content')
    @if ($state === 'form')
        <div class="au-head">
            <p class="au-eyebrow">Password reset</p>
            <h2 class="au-title">Choose a new password</h2>
            <p class="au-lead">Enter the email address of your CustodiCore {{ $isStaff ? 'staff' : 'visitor' }} account and your new password.</p>
        </div>

        <form method="POST" action="{{ route('password.update') }}" class="au-form" novalidate>
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div class="au-field">
                <label for="email" class="au-label">Email address</label>
                <input id="email" type="email" name="email" value="{{ old('email', $email) }}" autocomplete="email" required maxlength="100"
                       class="au-input {{ $errors->has('email') ? 'invalid' : '' }}">
                @error('email') <p class="au-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div class="au-field">
                <label for="password" class="au-label">New password</label>
                <div class="au-pw">
                    <input id="password" type="password" name="password" autocomplete="new-password" required
                           class="au-input {{ $errors->has('password') ? 'invalid' : '' }}">
                    <button type="button" class="au-eye" data-target="password" aria-label="Show password" aria-pressed="false" title="Show password">@include('auth.partials.eye-icons')</button>
                </div>
                {{-- No maxlength: the browser would silently cut a pasted password; the server rejects an over-long one instead. --}}
                <p class="au-hint">
                    @if ($isStaff)
                        At least 10 characters with upper and lower case letters, a number and a symbol. It cannot be your email or username.
                    @else
                        {{ \App\Support\VisitorPasswordPolicy::REQUIREMENTS }}
                    @endif
                </p>
                @error('password') <p class="au-error" role="alert">{{ $message }}</p> @enderror
            </div>

            <div class="au-field">
                <label for="password_confirmation" class="au-label">Confirm new password</label>
                <div class="au-pw">
                    <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required class="au-input">
                    <button type="button" class="au-eye" data-target="password_confirmation" aria-label="Show password" aria-pressed="false" title="Show password">@include('auth.partials.eye-icons')</button>
                </div>
            </div>

            @error('token') <p class="au-error" role="alert">This reset link is incomplete. Open the link from your email again.</p> @enderror

            <button type="submit" class="au-btn">Reset password</button>
        </form>

    @elseif ($state === 'done')
        <div class="au-state">
            <div class="au-state-icon ok"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg></div>
            <h2 class="au-title">Your password has been reset</h2>
            @if ($isStaff)
                <p>You can now sign in to the CustodiCore dashboard with your new password.</p>
                <p class="muted">For your security, every browser and device that was signed in to your account has been signed out. Two-step verification still applies when you sign in.</p>
                <a class="au-btn" href="{{ route('login') }}">Go to Sign In</a>
            @else
                <p>You can now log in to the CustodiCore app with your new password.</p>
                <p class="muted">For your security, any device that was signed in to your account has been signed out.</p>
                {{-- Opens Login only: no token, email or password. --}}
                @include('partials.open-app-link', [
                    'href' => 'custodicore://login',
                    'fallback' => 'If the app does not open, open CustodiCore on your phone yourself and sign in with your new password.',
                    'notOpened' => 'The CustodiCore app did not open. Open CustodiCore on your phone yourself and sign in with your new password.',
                ])
            @endif
        </div>

    @else
        <div class="au-state">
            <div class="au-state-icon err"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></div>
            <h2 class="au-title">This link is not valid</h2>
            <p>This password reset link is invalid, has expired, or has already been used.</p>
            <p class="muted">Visitors: open the CustodiCore app and tap “Forgot Password?” to request a new link. Staff: use <a href="{{ route('password.request') }}">Forgot password?</a> on the sign-in page. Each new email replaces the earlier ones — only the link in the newest email works.</p>
        </div>
    @endif
@endsection
