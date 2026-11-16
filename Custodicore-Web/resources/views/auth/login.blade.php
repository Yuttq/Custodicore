@extends('layouts.auth')

@section('title', 'Sign In')

@section('content')
    <div class="au-head">
        <p class="au-eyebrow">Staff sign-in</p>
        <h2 class="au-title">Welcome back</h2>
        <p class="au-lead">Use your staff email or the username you chose.</p>
    </div>

    <form method="POST" action="{{ route('login.attempt') }}" class="au-form">
        @csrf

        <div class="au-field">
            <label for="email" class="au-label">Email or username</label>
            <div class="au-iconwrap">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
                <input type="text" id="email" name="email" value="{{ old('email') }}" required autofocus
                       autocomplete="username" placeholder="e.g. juan.delacruz@gmail.com" class="au-input" />
            </div>
        </div>

        <div class="au-field">
            <div class="au-row">
                <label for="password" class="au-label">Password</label>
                <a href="{{ route('password.request') }}" class="au-link">Forgot password?</a>
            </div>
            <div class="au-iconwrap au-pw">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                <input type="password" id="password" name="password" required
                       autocomplete="current-password" placeholder="••••••••" class="au-input" />
                {{-- Show / hide the typed password. type="button" so it never submits the form. --}}
                <button type="button" id="toggle-password" class="au-eye" data-target="password"
                        aria-controls="password" aria-pressed="false" aria-label="Show password" title="Show password">@include('auth.partials.eye-icons')</button>
            </div>
        </div>

        <button type="submit" class="au-btn">
            Sign in
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
    </form>

    <p class="au-foot">Locked out? Contact your Admin.</p>
@endsection
