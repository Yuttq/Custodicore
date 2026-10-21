<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The URL of this page carries the reset token: never send it on. --}}
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>Reset password · CustodiCore</title>
    {{-- Public page opened from the visitor's inbox: no Vite / no login needed. --}}
    <style>
        :root { --navy: #0F3D7A; --teal: #0DA58A; --red: #DC2626; --amber: #F59E0B; --bg: #F8FAFC; --card: #FFFFFF; --border: #E5E7EB; --text: #111827; --muted: #6B7280; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; line-height: 1.6; }
        header { background: var(--navy); color: #fff; padding: 16px 24px; font-weight: 700; }
        main { max-width: 520px; margin: 40px auto; padding: 0 16px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 32px; text-align: center; }
        h1 { margin: 0 0 12px; font-size: 24px; color: var(--navy); }
        p { margin: 0 0 16px; }
        .muted { font-size: 14px; color: var(--muted); }
        .icon { width: 64px; height: 64px; border-radius: 50%; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center; font-size: 30px; font-weight: 700; color: #fff; }
        .icon.ok { background: var(--teal); }
        .icon.err { background: var(--red); }
        .icon.info { background: var(--navy); }
        .btn { display: inline-block; width: 100%; border: 0; cursor: pointer; background: var(--teal); color: #fff; font-size: 16px; font-weight: 600; text-decoration: none; padding: 12px 24px; border-radius: 10px; }
        a.btn { width: auto; }
        form { text-align: left; }
        .field { margin-bottom: 16px; }
        label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; }
        input { width: 100%; font-size: 16px; padding: 11px 12px; border: 1px solid var(--border); border-radius: 10px; background: #fff; color: var(--text); }
        input:focus { outline: 2px solid var(--teal); outline-offset: 1px; }
        input.invalid { border-color: var(--red); }
        .error { font-size: 13px; color: var(--red); margin-top: 6px; }
        .hint { font-size: 13px; color: var(--muted); margin-top: 6px; }
        .notice { font-size: 14px; color: var(--text); background: #FEF3C7; border: 1px solid var(--amber); border-radius: 10px; padding: 10px 12px; }
        @media (max-width: 480px) { main { margin: 24px auto; } .card { padding: 24px 20px; } }
    </style>
</head>
<body>
    <header>CustodiCore</header>
    <main>
        <div class="card">
            @if ($state === 'form')
                <div class="icon info">*</div>
                <h1>Choose a new password</h1>
                <p class="muted">Enter the email address of your CustodiCore visitor account and your new password.</p>
                <form method="POST" action="{{ route('password.update') }}" novalidate>
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">

                    <div class="field">
                        <label for="email">Email address</label>
                        <input id="email" type="email" name="email" value="{{ old('email', $email) }}" autocomplete="email" required maxlength="100" class="{{ $errors->has('email') ? 'invalid' : '' }}">
                        @error('email') <div class="error">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="password">New password</label>
                        <input id="password" type="password" name="password" autocomplete="new-password" required class="{{ $errors->has('password') ? 'invalid' : '' }}">
                        {{-- No maxlength: the browser would silently cut a pasted password; the server rejects > 64 instead. --}}
                        <div class="hint">{{ \App\Support\VisitorPasswordPolicy::REQUIREMENTS }}</div>
                        @error('password') <div class="error">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="password_confirmation">Confirm new password</label>
                        <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>
                    </div>

                    @error('token') <div class="error" style="margin-bottom:12px;">This reset link is incomplete. Open the link from your email again.</div> @enderror

                    <button type="submit" class="btn">Reset password</button>
                </form>
            @elseif ($state === 'done')
                <div class="icon ok">&#10003;</div>
                <h1>Your password has been reset</h1>
                <p>You can now log in to the CustodiCore app with your new password.</p>
                <p class="muted">For your security, any device that was signed in to your account has been signed out.</p>
                {{-- Opens Login only: no token, email or password. --}}
                @include('partials.open-app-link', [
                    'href' => 'custodicore://login',
                    'fallback' => 'If the app does not open, open CustodiCore on your phone yourself and sign in with your new password.',
                    'notOpened' => 'The CustodiCore app did not open. Open CustodiCore on your phone yourself and sign in with your new password.',
                ])
            @else
                <div class="icon err">&times;</div>
                <h1>This link is not valid</h1>
                <p>This password reset link is invalid, has expired, or has already been used.</p>
                <p class="muted">Open the CustodiCore app and tap “Forgot Password?” to request a new link. Each new email replaces the earlier ones — only the link in the newest email works.</p>
            @endif
        </div>
    </main>
</body>
</html>
