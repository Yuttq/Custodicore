@extends('auth.mfa-layout')

@section('title', 'Set up two-step verification')
@section('width', 'max-w-lg')

@section('body')
    <h1 class="text-card-title">Set up two-step verification</h1>
    <p class="mb-md mt-xs text-body text-text-secondary">
        Hello {{ $account->displayName() }}. Staff accounts need a second step when signing in. Choose how you want to get your codes.
    </p>

    {{-- Option B (no app): emailed codes. Shown first so officers who don't want an app see it right away. --}}
    <div class="mb-md rounded-card border border-border p-md">
        <p class="font-semibold">Option 1 — Email me a code each time <span class="text-metadata font-normal text-text-secondary">(no app needed)</span></p>
        <p class="mt-xs text-metadata text-text-secondary">
            Every time you sign in on a new or untrusted computer, we send a 6-digit code to {{ \App\Services\Auth\StaffMfaService::maskEmail($account->staffProfile?->personal_email ?: $account->email) }}.
        </p>
        <form method="POST" action="{{ route('mfa.setup.email') }}" class="mt-sm">
            @csrf
            <button type="submit" class="cc-btn-secondary w-full">Use email codes</button>
        </form>
    </div>

    <p class="mb-sm font-semibold">Option 2 — Use an authenticator app <span class="text-metadata font-normal text-text-secondary">(works even without email)</span></p>

    <ol class="space-y-md text-body">
        <li>
            <p class="font-semibold">1. Install an authenticator app</p>
            <p class="text-metadata text-text-secondary">Google Authenticator, Microsoft Authenticator, Authy or any app that supports time-based codes.</p>
        </li>

        <li>
            <p class="font-semibold">2. Add CustodiCore to it</p>
            <p class="text-metadata text-text-secondary">Scan this QR code with the app.</p>
            <div class="mt-sm flex justify-center">
                <div id="qr" class="h-48 w-48 rounded-card border border-border bg-white p-xs" aria-label="QR code for your authenticator app" role="img"></div>
            </div>
            <details class="mt-sm">
                <summary class="cursor-pointer text-metadata text-primary-navy underline">Can't scan? Type the key instead</summary>
                <p class="mt-xs text-metadata text-text-secondary">Choose "enter a setup key" in your app. Account: {{ $account->email }}. Type of key: time-based.</p>
                <p class="mt-xs select-all break-all rounded-sm bg-[#F3F4F6] p-sm font-mono text-body tracking-wider">{{ $groupedSecret }}</p>
            </details>
        </li>

        <li>
            <p class="font-semibold">3. Enter the 6-digit code it shows</p>
            <form method="POST" action="{{ route('mfa.setup.confirm') }}" class="mt-xs space-y-md" autocomplete="off">
                @csrf
                <input type="text" name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="10"
                       placeholder="123456" aria-label="6-digit code" class="cc-input text-center text-card-title tracking-widest" />
                <button type="submit" class="cc-btn-primary w-full">Turn on and sign in</button>
            </form>
        </li>
    </ol>

    <p class="mt-lg text-center text-metadata">
        <a href="{{ route('mfa.cancel') }}" class="text-primary-navy underline">Back to sign in</a>
    </p>

    @include('partials.qr-code-lib')
    <script>
        (function () {
            var el = document.getElementById('qr');
            try {
                var qr = qrcode(0, 'M');
                qr.addData({!! json_encode($otpUri, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!});
                qr.make();
                el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
                var svg = el.querySelector('svg');
                if (svg) { svg.style.width = '100%'; svg.style.height = '100%'; }
            } catch (e) {
                el.innerHTML = '<p class="p-sm text-metadata text-text-secondary">The QR code could not be drawn. Use the setup key below instead.</p>';
                var d = document.querySelector('details'); if (d) d.open = true;
            }
        })();
    </script>
@endsection
