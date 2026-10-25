<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- The URL of this page carries the setup token: never send it on. --}}
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>Set up your account · CustodiCore</title>
    {{-- Public page opened from the officer's inbox: no Vite / no login needed. --}}
    <style>
        :root { --navy: #0F3D7A; --green: #16A34A; --red: #DC2626; --amber: #F59E0B; --bg: #F8FAFC; --card: #FFFFFF; --border: #E5E7EB; --text: #111827; --muted: #6B7280; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; font-size: 13px; line-height: 1.5; }
        header { background: var(--navy); color: #fff; padding: 14px 24px; font-weight: 700; }
        main { max-width: 480px; margin: 32px auto; padding: 0 16px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 28px; box-shadow: 0 1px 6px 0 rgba(17,24,39,.06); }
        h1 { margin: 0 0 6px; font-size: 20px; color: var(--navy); }
        p { margin: 0 0 14px; }
        .muted { color: var(--muted); font-size: 12px; }
        .who { background: var(--bg); border: 1px solid var(--border); border-radius: 12px; padding: 10px 14px; margin: 0 0 18px; }
        .who b { display: block; font-size: 14px; }
        label { display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); margin: 14px 0 4px; }
        input { width: 100%; height: 38px; padding: 0 12px; border: 1px solid var(--border); border-radius: 12px; font: inherit; color: var(--text); background: #fff; }
        input:focus { outline: none; border-color: var(--green); box-shadow: 0 0 0 3px rgba(22,163,74,.2); }
        .hint { font-size: 11px; color: var(--muted); margin-top: 4px; }
        .btn { width: 100%; height: 38px; margin-top: 20px; border: 0; cursor: pointer; background: var(--green); color: #fff; font: inherit; font-weight: 600; border-radius: 12px; }
        .errors { background: #FEF2F2; border-left: 4px solid var(--red); border-radius: 8px; padding: 10px 14px; margin-bottom: 8px; color: #991B1B; }
        .errors ul { margin: 0; padding-left: 18px; }
        .center { text-align: center; }
        .badge { width: 56px; height: 56px; border-radius: 50%; margin: 0 auto 14px; display: flex; align-items: center; justify-content: center; font-size: 26px; font-weight: 700; color: #fff; }
        .badge.warn { background: var(--amber); } .badge.err { background: var(--red); }
    </style>
</head>
<body>
    <header>CustodiCore</header>
    <main>
        <div class="card">
            @if ($state === 'form')
                <h1>Set up your staff account</h1>
                <p class="muted">Choose your own password. Nobody else, including the Admin, will ever see it. This link works once and is valid for {{ $hours }} hours from when it was sent.</p>

                <div class="who">
                    <b>{{ $account->displayName() }}</b>
                    <span class="muted">{{ \App\Models\Role::label($account->role?->role_name) }} · {{ $account->email }}</span>
                </div>

                @if ($errors->any())
                    <div class="errors"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                @endif

                <form method="POST" action="{{ route('staff-setup.submit') }}" autocomplete="off">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">

                    <label for="username">Username</label>
                    <input id="username" name="username" type="text" required minlength="4" maxlength="30"
                           value="{{ old('username', $account->username) }}" autocapitalize="none" spellcheck="false">
                    <div class="hint">You can sign in with this username or with {{ $account->email }}. Must be unique. Lowercase letters, numbers, . - _</div>

                    <label for="contact_number">Mobile number</label>
                    <input id="contact_number" name="contact_number" type="tel" inputmode="tel" required maxlength="20"
                           value="{{ old('contact_number', $account->staffProfile?->contact_number) }}" placeholder="0917 123 4567">
                    <div class="hint">Used for account notices. Check that it is correct.</div>

                    <label for="password">New password</label>
                    <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
                    <div class="hint">At least 10 characters with upper and lower case letters, a number and a symbol.</div>

                    <label for="password_confirmation">Confirm password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required minlength="10" autocomplete="new-password">

                    <button type="submit" class="btn">Save and activate my account</button>
                </form>
            @elseif ($state === 'expired')
                <div class="center">
                    <div class="badge warn">!</div>
                    <h1>This link has expired</h1>
                    <p>For your security, setup links expire. Ask the Admin to send you a new one.</p>
                </div>
            @else
                <div class="center">
                    <div class="badge err">&times;</div>
                    <h1>This link is not valid</h1>
                    <p>It may already have been used, or replaced by a newer link. If you have not finished setting up, ask the Admin to send you a new one. If you already did, <a href="{{ route('login') }}">sign in here</a>.</p>
                </div>
            @endif
        </div>
    </main>
</body>
</html>
