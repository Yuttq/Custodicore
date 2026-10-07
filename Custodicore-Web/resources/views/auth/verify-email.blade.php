<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The URL of this page carries the verification token: never send it on. --}}
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>Email verification · CustodiCore</title>
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
        .icon.warn { background: var(--amber); }
        .icon.err { background: var(--red); }
        .icon.info { background: var(--navy); }
        .btn { display: inline-block; border: 0; cursor: pointer; background: var(--teal); color: #fff; font-size: 16px; font-weight: 600; text-decoration: none; padding: 12px 24px; border-radius: 10px; }
    </style>
</head>
<body>
    <header>CustodiCore</header>
    <main>
        <div class="card">
            @if ($state === 'confirm')
                <div class="icon info">@</div>
                <h1>Verify your email address</h1>
                <p>Tap the button below to confirm this email address for your CustodiCore visitor account.</p>
                <form method="POST" action="{{ route('email-verification.verify') }}">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <button type="submit" class="btn">Verify my email</button>
                </form>
            @elseif ($state === 'verified' || $state === 'already_verified')
                <div class="icon ok">&#10003;</div>
                <h1>{{ $state === 'verified' ? 'Your email has been verified' : 'Your email is already verified' }}</h1>
                <p>You can now log in to the CustodiCore app.</p>
                <p class="muted">After you log in, our staff will review the information and documents you submitted. You will be notified once your account has been approved.</p>
                <p><a class="btn" href="custodicore://login?emailVerified=1">Open the CustodiCore app</a></p>
            @elseif ($state === 'expired')
                <div class="icon warn">!</div>
                <h1>This link has expired</h1>
                <p>For your security, verification links expire. Open the CustodiCore app and request a new verification email.</p>
            @else
                <div class="icon err">&times;</div>
                <h1>This link is not valid</h1>
                <p>This verification link is invalid or has already been used. If your email is not verified yet, open the CustodiCore app and request a new verification email.</p>
                <p class="muted">Requested another verification email? Each new email replaces the earlier ones — only the link in the newest email works.</p>
            @endif
        </div>
    </main>
</body>
</html>
