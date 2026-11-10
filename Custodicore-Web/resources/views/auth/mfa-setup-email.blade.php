@extends('auth.mfa-layout')

@section('title', 'Set up two-step verification')
@section('width', 'max-w-[372px]')
@section('brand_gap', 'mb-md')

@section('body')
    <h1 class="text-card-title">Check your email</h1>
    <p class="mb-md mt-xs text-body text-text-secondary">
        Hello {{ $account->displayName() }}. Enter the 6-digit code we sent to <span class="font-semibold">{{ $emailTo }}</span>
        to turn on two-step verification with emailed codes.
    </p>

    <form method="POST" action="{{ route('mfa.setup.email.confirm') }}" class="space-y-[12px]" autocomplete="off">
        @csrf

        <div>
            <label for="code" class="cc-label">Code from the email</label>
            <input type="text" id="code" name="code" required autofocus
                   inputmode="numeric" autocomplete="one-time-code" maxlength="10"
                   placeholder="123456" class="cc-input h-[36px] text-center text-card-title tracking-widest" />
            <p class="mt-xs text-metadata text-text-secondary">Not there? Check your spam folder.</p>
        </div>

        @if (config('mfa.trusted_days') > 0)
            <label class="flex items-start gap-sm text-body">
                <input type="checkbox" name="trust_device" value="1" class="mt-[3px]" />
                <span>Trust this browser for {{ config('mfa.trusted_days') }} days<br>
                    <span class="text-metadata text-text-secondary">Only on your own computer. Not on a shared one.</span>
                </span>
            </label>
        @endif

        <button type="submit" class="cc-btn-primary h-[36px] w-full">Turn on and sign in</button>
    </form>

    <div class="mt-md border-t border-border pt-[12px]">
        <form method="POST" action="{{ route('mfa.setup.email') }}">
            @csrf
            <button type="submit" class="cc-btn-secondary h-[36px] w-full">Send a new code</button>
        </form>
    </div>

    <p class="mt-md text-center text-metadata">
        <a href="{{ route('mfa.setup') }}" class="text-primary-navy underline">Use an authenticator app instead</a>
        &middot;
        <a href="{{ route('mfa.cancel') }}" class="text-primary-navy underline">Back to sign in</a>
    </p>
@endsection
