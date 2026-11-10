@extends('auth.mfa-layout')

@section('title', 'Two-step verification')
@section('width', 'max-w-[372px]')
@section('brand_gap', 'mb-md')

@section('body')
    @php $usesEmail = $usesEmail ?? false; @endphp
    <h1 class="text-card-title">Two-step verification</h1>
    @if ($usesEmail)
        @if (! empty($sendError))
            <div class="mb-md mt-xs border-l-4 border-l-danger bg-danger/5 p-md rounded-sm">
                <p class="text-body text-danger">{{ $sendError }}</p>
            </div>
        @endif
        <p class="mb-md mt-xs text-body text-text-secondary">
            Hello {{ $account->displayName() }}. We emailed a 6-digit code to <span class="font-semibold">{{ $emailTo }}</span>.
            Enter it to finish signing in.
        </p>
    @else
        <p class="mb-md mt-xs text-body text-text-secondary">
            Hello {{ $account->displayName() }}. Enter the 6-digit code from your authenticator app to finish signing in.
        </p>
    @endif

    <form method="POST" action="{{ route('mfa.verify') }}" class="space-y-[12px]" autocomplete="off">
        @csrf

        <div>
            <label for="code" class="cc-label">Verification code</label>
            <input type="text" id="code" name="code" required autofocus
                   inputmode="{{ $usesEmail ? 'numeric' : 'text' }}" autocomplete="one-time-code" maxlength="32"
                   placeholder="123456" class="cc-input h-[36px] text-center text-card-title tracking-widest" />
            <p class="mt-xs text-metadata text-text-secondary">
                @if ($usesEmail)
                    Not there? Check your spam folder. The code works for {{ config('mfa.email_code_minutes') }} minutes.
                @else
                    You can also type a backup code (like ABCDE-FGHJK) or the code we email you.
                @endif
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

        <button type="submit" class="cc-btn-primary h-[36px] w-full">Verify and sign in</button>
    </form>

    <div class="mt-md border-t border-border pt-[12px]">
        <p class="text-metadata text-text-secondary">{{ $usesEmail ? 'No email yet, or the code expired?' : 'Authenticator app not with you?' }}</p>
        <form method="POST" action="{{ route('mfa.email') }}" class="mt-xs">
            @csrf
            <button type="submit" class="cc-btn-secondary h-[36px] w-full">{{ $usesEmail ? 'Send a new code' : 'Email me a code' }}</button>
        </form>
    </div>

    <p class="mt-md text-center text-metadata text-text-secondary">
        @if ($usesEmail)
            Can't open your email? Ask the system administrator to reset your two-step verification.
        @else
            Lost your phone and your backup codes? Ask the system administrator to reset your two-step verification.
        @endif
    </p>
    <p class="mt-xs text-center text-metadata">
        <a href="{{ route('mfa.cancel') }}" class="text-primary-navy underline">Back to sign in</a>
    </p>
@endsection
