@extends('auth.mfa-layout')

@section('title', 'Two-step verification')

@section('body')
    <h1 class="text-card-title">Two-step verification</h1>
    <p class="mb-lg mt-xs text-body text-text-secondary">
        Hello {{ $account->displayName() }}. Enter the 6-digit code from your authenticator app to finish signing in.
    </p>

    <form method="POST" action="{{ route('mfa.verify') }}" class="space-y-md" autocomplete="off">
        @csrf

        <div>
            <label for="code" class="cc-label">Verification code</label>
            <input type="text" id="code" name="code" required autofocus
                   inputmode="text" autocomplete="one-time-code" maxlength="32"
                   placeholder="123456" class="cc-input text-center text-card-title tracking-widest" />
            <p class="mt-xs text-metadata text-text-secondary">
                You can also type a backup code (like ABCDE-FGHJK) or the code we email you.
            </p>
        </div>

        @if (config('mfa.trusted_days') > 0)
            <label class="flex items-start gap-sm text-body">
                <input type="checkbox" name="trust_device" value="1" class="mt-[3px]" />
                <span>Trust this browser for {{ config('mfa.trusted_days') }} days<br>
                    <span class="text-metadata text-text-secondary">Only on your own computer. Not on a shared one.</span>
                </span>
            </label>
        @endif

        <button type="submit" class="cc-btn-primary w-full">Verify and sign in</button>
    </form>

    <div class="mt-lg border-t border-border pt-md">
        <p class="text-metadata text-text-secondary">Authenticator app not with you?</p>
        <form method="POST" action="{{ route('mfa.email') }}" class="mt-xs">
            @csrf
            <button type="submit" class="cc-btn-secondary w-full">Email me a code</button>
        </form>
    </div>

    <p class="mt-lg text-center text-metadata text-text-secondary">
        Lost your phone and your backup codes? Ask another Admin to reset your two-step verification.
    </p>
    <p class="mt-sm text-center text-metadata">
        <a href="{{ route('mfa.cancel') }}" class="text-primary-navy underline">Back to sign in</a>
    </p>
@endsection
