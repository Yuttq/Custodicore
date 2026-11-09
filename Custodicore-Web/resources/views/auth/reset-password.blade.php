<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The URL of this page carries the reset token: never send it on. --}}
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>Reset password · CustodiCore</title>
    {{-- Public page opened from the visitor's or officer's inbox: no Vite / no login needed. --}}
    @php $isStaff = $isStaff ?? false; @endphp
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
        .pw-wrap { position: relative; }
        .pw-wrap input { padding-right: 44px; }
        .pw-toggle { position: absolute; top: 0; bottom: 0; right: 0; padding: 0 12px; border: 0; background: none; cursor: pointer; color: var(--muted); display: flex; align-items: center; }
        .pw-toggle:hover { color: var(--navy); }
        @media (max-width: 480px) { main { margin: 24px auto; } .card { padding: 24px 20px; } }
    </style>
@include('partials.favicon')
</head>
<body>
    <header>CustodiCore</header>
    <main>
        <div class="card">
            @if ($state === 'form')
                <div class="icon info">*</div>
                <h1>Choose a new password</h1>
                <p class="muted">Enter the email address of your CustodiCore {{ $isStaff ? 'staff' : 'visitor' }} account and your new password.</p>
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
                        <div class="pw-wrap">
                            <input id="password" type="password" name="password" autocomplete="new-password" required class="{{ $errors->has('password') ? 'invalid' : '' }}">
                            <button type="button" class="pw-toggle" data-target="password" aria-label="Show password" aria-pressed="false" title="Show password"><svg class="icon-eye" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg><svg class="icon-eye-off" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 19c-6.4 0-10-7-10-7a18.5 18.5 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.4 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="2" y1="2" x2="22" y2="22"/></svg></button>
                        </div>
                        {{-- No maxlength: the browser would silently cut a pasted password; the server rejects an over-long one instead. --}}
                        <div class="hint">
                            @if ($isStaff)
                                At least 10 characters with upper and lower case letters, a number and a symbol. It cannot be your email or username.
                            @else
                                {{ \App\Support\VisitorPasswordPolicy::REQUIREMENTS }}
                            @endif
                        </div>
                        @error('password') <div class="error">{{ $message }}</div> @enderror
                    </div>

                    <div class="field">
                        <label for="password_confirmation">Confirm new password</label>
                        <div class="pw-wrap">
                            <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>
                            <button type="button" class="pw-toggle" data-target="password_confirmation" aria-label="Show password" aria-pressed="false" title="Show password"><svg class="icon-eye" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg><svg class="icon-eye-off" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 19c-6.4 0-10-7-10-7a18.5 18.5 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.4 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="2" y1="2" x2="22" y2="22"/></svg></button>
                        </div>
                    </div>

                    @error('token') <div class="error" style="margin-bottom:12px;">This reset link is incomplete. Open the link from your email again.</div> @enderror

                    <button type="submit" class="btn">Reset password</button>
                </form>
                <script>
                    (function () {
                        var buttons = document.querySelectorAll('.pw-toggle');
                        buttons.forEach(function (btn) {
                            btn.addEventListener('click', function () {
                                var input = document.getElementById(btn.getAttribute('data-target'));
                                var show = input.type === 'password';
                                input.type = show ? 'text' : 'password';
                                btn.querySelector('.icon-eye').style.display = show ? 'none' : '';
                                btn.querySelector('.icon-eye-off').style.display = show ? '' : 'none';
                                btn.setAttribute('title', show ? 'Hide password' : 'Show password');
                                btn.setAttribute('aria-pressed', show ? 'true' : 'false');
                                btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                            });
                        });
                        // Hide both again before the form is sent.
                        var form = buttons.length ? buttons[0].closest('form') : null;
                        if (form) form.addEventListener('submit', function () {
                            buttons.forEach(function (btn) { document.getElementById(btn.getAttribute('data-target')).type = 'password'; });
                        });
                    })();
                </script>
            @elseif ($state === 'done')
                <div class="icon ok">&#10003;</div>
                <h1>Your password has been reset</h1>
                @if ($isStaff)
                <p>You can now sign in to the CustodiCore dashboard with your new password.</p>
                <p class="muted">For your security, every browser and device that was signed in to your account has been signed out. Two-step verification still applies when you sign in.</p>
                <a class="btn" href="{{ route('login') }}">Go to Sign In</a>
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
            @else
                <div class="icon err">&times;</div>
                <h1>This link is not valid</h1>
                <p>This password reset link is invalid, has expired, or has already been used.</p>
                <p class="muted">Visitors: open the CustodiCore app and tap “Forgot Password?” to request a new link. Staff: use <a href="{{ route('password.request') }}">Forgot password?</a> on the sign-in page. Each new email replaces the earlier ones — only the link in the newest email works.</p>
            @endif
        </div>
    </main>
</body>
</html>
