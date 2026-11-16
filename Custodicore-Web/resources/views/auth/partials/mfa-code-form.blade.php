{{--
    The emailed-code form shared by mfa-challenge (every sign-in) and mfa-setup (first sign-in).
    $step, $heading, $intro, $after, $action, $button — plus $emailTo and $sendError from MfaController.
--}}
<div style="display: flex; flex-direction: column; gap: 10px;">
    <div style="display: flex; gap: 8px;" aria-hidden="true">
        <span style="flex: 1; height: 6px; border-radius: 999px; background: #0F8A8A;"></span>
        <span style="flex: 1; height: 6px; border-radius: 999px; background: #0F8A8A;"></span>
    </div>
    <p class="au-eyebrow">{{ $step }}</p>
</div>

<div class="au-head">
    <h2 class="au-title">{{ $heading }}</h2>
    <p class="au-lead">{{ $intro }} We emailed a 6-digit code to <strong style="color: #111827;">{{ $emailTo }}</strong>. {{ $after }}</p>
</div>

@if (! empty($sendError))
    <div class="au-alert error" role="alert">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 7v6M12 17h.01"/></svg>
        <p>{{ $sendError }}</p>
    </div>
@endif

<form method="POST" action="{{ $action }}" class="au-form" autocomplete="off">
    @csrf

    <div class="au-field">
        <label for="code" class="au-label">Code from the email</label>
        <input type="text" id="code" name="code" required autofocus
               inputmode="numeric" autocomplete="one-time-code" maxlength="32"
               placeholder="123456" class="au-input au-code" />
        <p class="au-hint">Not there? Check your spam folder. The code works for {{ config('mfa.email_code_minutes') }} minutes.</p>
    </div>

    @if (config('mfa.trusted_days') > 0)
        <label class="au-check">
            <input type="checkbox" name="trust_device" value="1" />
            <span>
                <strong>Trust this browser for {{ config('mfa.trusted_days') }} days</strong>
                <span>Only on your own computer, never a shared one.</span>
            </span>
        </label>
    @endif

    <button type="submit" class="au-btn">{{ $button }}</button>
</form>

<div class="au-divider">
    <p class="au-hint">No email yet, or the code expired?</p>
    <form method="POST" action="{{ route('mfa.email') }}" style="margin: 0;">
        @csrf
        <button type="submit" class="au-btn-secondary">Send a new code</button>
    </form>
</div>

<p class="au-foot">Can't open your email? Ask the system administrator for help. · <a href="{{ route('mfa.cancel') }}" style="font-weight: 600;">Back to sign in</a></p>
