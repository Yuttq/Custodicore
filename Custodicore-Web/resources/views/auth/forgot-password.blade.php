@extends('layouts.auth')

@section('title', 'Forgot Password')
@section('hero_title', 'Back in, in a minute.')
@section('hero_text', 'We email a one-time link to the address on your staff account. Nobody else, including the Admin, ever sees your new password.')
{{-- Errors are shown under the email field instead of at the top. --}}
@section('no_flash', '1')

@section('content')
    <a href="{{ route('login') }}" class="au-back">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>
        Back to sign in
    </a>

    <div class="au-head">
        <h2 class="au-title">Forgot your password?</h2>
        <p class="au-lead">Enter the email registered to your staff account and we'll send you a link to choose a new one.</p>
    </div>

    @if (session('status'))
        <div class="au-alert info" role="status">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg>
            <p>{{ session('status') }}</p>
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="au-form">
        @csrf

        <div class="au-field">
            <label for="email" class="au-label">Email address</label>
            <div class="au-iconwrap">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                       maxlength="100" autocomplete="email" placeholder="e.g. juan.delacruz@gmail.com"
                       class="au-input {{ $errors->has('email') ? 'invalid' : '' }}" />
            </div>
            @error('email')
                <p class="au-error" role="alert">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="au-btn">Send reset link</button>
    </form>

    <div class="au-alert info">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
        <p>The link works once. Didn't get it? Check your spam folder before asking again. No longer have access to that email? Contact your Admin.</p>
    </div>
@endsection
