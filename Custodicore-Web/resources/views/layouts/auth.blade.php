<!DOCTYPE html>
<html lang="en">
{{--
    Option A "split panel" layout for every page before the dashboards:
    sign in, forgot password, two-step code, reset password, staff setup.

    Self-contained (inline CSS, no Vite) so the public pages opened from an
    email work even without built assets.

    Sections a page can set:
      title        tab title (" · CustodiCore" is added)
      hero_title   big line on the navy panel (none = no headline, e.g. sign in)
      hero_text    paragraph under it
      hero_note    small line in the lock box (default: authorized personnel only)
      content      the form side
      no_seal      set it (any value) on public email-link pages: shows a "CC"
                   badge instead of the BJMP seal (see StaffSetupLinkDomainTest)
      no_flash     set it when the page shows its own errors next to the fields
      no_referrer  set it on pages whose URL carries a token
--}}
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    @hasSection('no_referrer')
        <meta name="referrer" content="no-referrer">
    @endif
    <title>@yield('title') · CustodiCore</title>
@include('partials.favicon')
    <style>
        :root {
            --navy: #0F3D7A; --navy-dark: #0A2C59; --teal: #0F8A8A; --teal-text: #0F766E; --teal-light: #99F6E4;
            --green: #15803D; --green-dark: #166534; --red: #DC2626; --red-text: #B91C1C;
            --bg: #F8FAFC; --card: #FFFFFF; --border: #D1D5DB; --line: #E5E7EB;
            --text: #111827; --body: #4B5563; --muted: #6B7280; --label: #374151;
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; line-height: 1.5; }
        a { color: var(--navy); }
        a:hover { color: var(--navy-dark); }

        .au { min-height: 100vh; display: flex; flex-wrap: wrap; }

        /* Navy brand panel */
        .au-brand { flex: 0 1 520px; min-width: 0; position: relative; overflow: hidden; background: var(--navy); color: #fff; padding: 56px 56px 40px; display: flex; flex-direction: column; gap: 32px; }
        .au-brand::before { content: ""; position: absolute; right: -180px; bottom: -200px; width: 460px; height: 460px; border-radius: 50%; background: var(--teal); opacity: .35; }
        .au-brand::after { content: ""; position: absolute; right: -60px; bottom: -80px; width: 240px; height: 240px; border-radius: 50%; border: 2px solid rgba(255,255,255,.18); }
        .au-brand > * { position: relative; z-index: 1; }
        .au-id { display: flex; align-items: center; gap: 16px; }
        .au-seal { width: 64px; height: 64px; object-fit: contain; }
        .au-badge { width: 56px; height: 56px; border-radius: 16px; background: rgba(255,255,255,.12); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 20px; }
        .au-name { margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -.01em; }
        .au-sub { margin: 2px 0 0; font-size: 11px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: var(--teal-light); }
        .au-hero { margin-top: auto; display: flex; flex-direction: column; gap: 16px; }
        .au-hero h1 { margin: 0; font-size: 40px; line-height: 1.12; font-weight: 800; letter-spacing: -.02em; }
        .au-hero p { margin: 0; font-size: 16px; line-height: 1.6; color: #DBEAFE; max-width: 400px; }
        .au-lock { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-radius: 12px; background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.14); }
        .au-lock p { margin: 0; font-size: 13px; line-height: 1.5; color: #E0F2FE; }

        /* Form side */
        .au-main { flex: 1 1 480px; min-width: 0; display: flex; align-items: center; justify-content: center; padding: 56px 24px; }
        .au-box { width: 100%; max-width: 440px; display: flex; flex-direction: column; gap: 24px; }
        .au-eyebrow { margin: 0; font-size: 12px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--teal-text); }
        .au-title { margin: 0; font-size: 32px; font-weight: 800; letter-spacing: -.02em; color: var(--navy); line-height: 1.15; }
        .au-lead { margin: 0; font-size: 15px; line-height: 1.6; color: var(--body); }
        .au-head { display: flex; flex-direction: column; gap: 8px; }
        .au-form { display: flex; flex-direction: column; gap: 18px; margin: 0; }
        .au-field { display: flex; flex-direction: column; gap: 8px; }
        .au-row { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; }
        .au-label { font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--label); }
        .au-input { width: 100%; height: 48px; padding: 0 14px; border: 1px solid var(--border); border-radius: 12px; font: inherit; font-size: 15px; background: #fff; color: var(--text); }
        .au-input:focus { outline: none; border-color: var(--teal); box-shadow: 0 0 0 3px rgba(15,138,138,.18); }
        .au-input.invalid { border-color: var(--red); border-width: 1.5px; }
        .au-iconwrap, .au-pw { position: relative; }
        .au-iconwrap > svg { position: absolute; left: 14px; top: 15px; color: var(--muted); pointer-events: none; }
        .au-iconwrap .au-input { padding-left: 44px; }
        .au-pw .au-input { padding-right: 52px; }
        .au-eye { position: absolute; right: 2px; top: 2px; width: 44px; height: 44px; border: 0; background: none; color: var(--muted); cursor: pointer; display: flex; align-items: center; justify-content: center; border-radius: 10px; }
        .au-eye:hover { color: var(--navy); }
        .au-code { height: 60px; text-align: center; font-size: 28px; font-weight: 700; letter-spacing: .4em; color: var(--navy); padding-left: .4em; }
        .au-hint { margin: 0; font-size: 13px; color: var(--muted); }
        .au-error { margin: 0; font-size: 13px; color: var(--red-text); }
        .au-link { font-size: 13px; font-weight: 600; color: var(--teal-text); }
        .au-btn { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; height: 50px; border: 0; border-radius: 12px; background: var(--green); color: #fff; font: inherit; font-size: 16px; font-weight: 700; cursor: pointer; text-decoration: none; }
        .au-btn:hover { background: var(--green-dark); color: #fff; }
        .au-btn:disabled { opacity: .6; cursor: not-allowed; }
        .au-btn-secondary { display: flex; align-items: center; justify-content: center; width: 100%; min-height: 46px; padding: 0 16px; border: 1px solid var(--border); border-radius: 12px; background: #fff; color: var(--label); font: inherit; font-size: 14px; font-weight: 600; cursor: pointer; }
        .au-btn-secondary:hover { border-color: var(--navy); color: var(--navy); }
        .au-back { display: inline-flex; align-items: center; gap: 6px; align-self: flex-start; min-height: 44px; font-size: 14px; font-weight: 600; text-decoration: none; }
        .au-alert { display: flex; gap: 10px; padding: 12px 14px; border-radius: 12px; font-size: 14px; line-height: 1.5; }
        .au-alert p, .au-alert ul { margin: 0; }
        .au-alert ul { padding-left: 18px; }
        .au-alert.error { background: #FEF2F2; border: 1px solid #FECACA; color: #991B1B; }
        .au-alert.info { background: #EFF6FF; border: 1px solid #DBEAFE; color: #1E3A8A; }
        .au-alert svg { flex-shrink: 0; margin-top: 2px; }
        .au-check { display: flex; align-items: flex-start; gap: 12px; padding: 14px 16px; border-radius: 12px; border: 1px solid var(--line); background: #fff; cursor: pointer; }
        .au-check input { width: 18px; height: 18px; margin: 2px 0 0; accent-color: var(--teal); }
        .au-check strong { display: block; font-size: 14px; color: var(--text); }
        .au-check span span { font-size: 13px; color: var(--muted); }
        .au-who { display: flex; align-items: center; gap: 14px; padding: 16px; border-radius: 14px; background: #fff; border: 1px solid var(--line); }
        .au-avatar { width: 48px; height: 48px; flex-shrink: 0; border-radius: 50%; background: var(--navy); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 16px; font-weight: 700; }
        .au-who p { margin: 0; }
        .au-who .n { font-size: 16px; font-weight: 700; }
        .au-who .e { font-size: 13px; color: var(--muted); overflow-wrap: anywhere; }
        .au-chip { flex-shrink: 0; padding: 4px 10px; border-radius: 999px; background: #CCFBF1; color: #115E59; font-size: 12px; font-weight: 700; }
        .au-divider { padding-top: 20px; border-top: 1px solid var(--line); display: flex; flex-direction: column; gap: 10px; }
        .au-foot { margin: 0; font-size: 13px; text-align: center; color: var(--muted); }
        .au-state { display: flex; flex-direction: column; align-items: flex-start; gap: 14px; }
        .au-state-icon { width: 56px; height: 56px; border-radius: 16px; display: flex; align-items: center; justify-content: center; }
        .au-state-icon.ok { background: #DCFCE7; color: var(--green); }
        .au-state-icon.err { background: #FEE2E2; color: var(--red); }
        .au-state-icon.warn { background: #FEF3C7; color: #B45309; }
        .au-state-icon.info { background: #CCFBF1; color: var(--teal-text); }
        .au-state p { margin: 0; color: var(--body); line-height: 1.6; }
        .au-grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 16px; }

        @media (max-width: 1000px) {
            .au-brand { flex-basis: 100%; padding: 28px 24px; gap: 18px; }
            .au-hero h1 { font-size: 26px; }
            .au-hero p, .au-lock { display: none; }
            .au-main { padding: 32px 16px 48px; }
        }
    </style>
    @stack('head')
</head>
<body>
<div class="au">
    <aside class="au-brand">
        <div class="au-id">
            @hasSection('no_seal')
                <div class="au-badge" aria-hidden="true">CC</div>
            @else
                <img class="au-seal" src="{{ asset('images/bjmp-logo.png') }}" alt="Bureau of Jail Management and Penology logo"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <div class="au-badge" style="display:none;" aria-hidden="true">CC</div>
            @endif
            <div>
                <p class="au-name">CustodiCore</p>
                <p class="au-sub">BJMP Facility Management System</p>
            </div>
        </div>
        @hasSection('hero_title')
            <div class="au-hero">
                <h1>@yield('hero_title')</h1>
                @hasSection('hero_text')<p>@yield('hero_text')</p>@endif
            </div>
        @else
            {{-- No headline (sign-in page): keeps the lock note at the bottom. --}}
            <div class="au-hero" aria-hidden="true"></div>
        @endif
        <div class="au-lock">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#99F6E4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
            <p>@yield('hero_note', 'For authorized BJMP personnel only. Every sign-in is recorded.')</p>
        </div>
    </aside>

    <main class="au-main">
        <div class="au-box">
            @sectionMissing('no_flash')
                @if ($errors->any())
                    <div class="au-alert error" role="alert">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 7v6M12 17h.01"/></svg>
                        <p>{{ $errors->first() }}</p>
                    </div>
                @endif
                @if (session('status'))
                    <div class="au-alert info" role="status">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 11v6M12 7h.01"/></svg>
                        <p>{{ session('status') }}</p>
                    </div>
                @endif
            @endif

            @yield('content')
        </div>
    </main>
</div>
<script>
    (function () {
        // Show / hide password: buttons .au-eye[data-target]. Crossed-out eye = hidden, open eye = visible.
        var buttons = document.querySelectorAll('.au-eye[data-target]');
        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var input = document.getElementById(btn.getAttribute('data-target'));
                if (!input) return;
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.querySelector('.icon-eye').style.display = show ? '' : 'none';
                btn.querySelector('.icon-eye-off').style.display = show ? 'none' : '';
                btn.setAttribute('aria-pressed', show ? 'true' : 'false');
                btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                btn.setAttribute('title', show ? 'Hide password' : 'Show password');
            });
        });
        // Every form: hide passwords again and block a double submit.
        document.querySelectorAll('form').forEach(function (form) {
            form.addEventListener('submit', function () {
                form.querySelectorAll('.au-eye[data-target]').forEach(function (btn) {
                    var input = document.getElementById(btn.getAttribute('data-target'));
                    if (input) input.type = 'password';
                });
                var submit = form.querySelector('button[type="submit"]');
                if (submit) setTimeout(function () { submit.disabled = true; }, 0);
            });
        });
        window.addEventListener('pageshow', function (e) {
            if (e.persisted) document.querySelectorAll('button[type="submit"][disabled]').forEach(function (b) { b.disabled = false; });
        });
    })();
</script>
</body>
</html>
