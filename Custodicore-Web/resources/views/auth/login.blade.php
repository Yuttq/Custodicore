<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In · CustodiCore</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
@include('partials.favicon')
</head>
<body class="min-h-screen bg-primary-navy text-text-primary">
    <div class="flex min-h-screen items-center justify-center px-md py-xl">
        <div class="w-full max-w-md">
            {{-- Brand mark. The BJMP logo is the file public/images/bjmp-logo.png; until that file exists
                 (or if it fails to load) the "CC" badge below is shown instead, so the page never breaks. --}}
            <div class="mb-lg flex flex-col items-center text-center">
                <img src="{{ asset('images/bjmp-logo.png') }}" alt="Bureau of Jail Management and Penology logo"
                     class="mb-sm h-24 w-24 object-contain"
                     onerror="this.style.display='none'; document.getElementById('login-badge').style.display='flex';">
                <div id="login-badge" style="display:none;" class="mb-sm h-14 w-14 items-center justify-center rounded-card bg-white/10 text-card-title font-bold text-white">CC</div>
                <p class="text-page-title text-white">CustodiCore</p>
                <p class="text-status-label uppercase tracking-wide text-white/60">BJMP Facility Management System</p>
            </div>

            <div class="cc-card">
                <h1 class="mb-lg text-card-title">Staff Sign In</h1>

                @if ($errors->any())
                    <div class="mb-md border-l-4 border-l-danger bg-danger/5 p-md rounded-sm">
                        <p class="text-body text-danger">{{ $errors->first() }}</p>
                    </div>
                @endif

                @if (session('status'))
                    <div class="mb-md border-l-4 border-l-info bg-info/5 p-md rounded-sm">
                        <p class="text-body text-text-primary">{{ session('status') }}</p>
                    </div>
                @endif

                <form method="POST" action="{{ route('login.attempt') }}" class="space-y-md">
                    @csrf

                    <div>
                        <label for="email" class="cc-label">Email or Username</label>
                        <input type="text" id="email" name="email" value="{{ old('email') }}" required autofocus
                               autocomplete="username" placeholder="Your email or username" class="cc-input" />
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <label for="password" class="cc-label">Password</label>
                            <a href="{{ route('password.request') }}" class="text-metadata font-semibold text-primary-teal hover:underline">Forgot password?</a>
                        </div>
                        <div style="position:relative;">
                            <input type="password" id="password" name="password" required
                                   autocomplete="current-password" placeholder="••••••••" class="cc-input" style="padding-right:2.75rem;" />
                            {{-- Show / hide the typed password. type="button" so it never submits the form. --}}
                            <button type="button" id="toggle-password"
                                    class="text-metadata font-semibold text-text-secondary hover:text-primary-navy"
                                    style="position:absolute; top:0; bottom:0; right:0; display:flex; align-items:center; padding:0 12px; background:none; border:0; cursor:pointer;"
                                    aria-controls="password" aria-pressed="false" aria-label="Show password" title="Show password"><svg class="icon-eye" style="display:none;" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg><svg class="icon-eye-off" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 19c-6.4 0-10-7-10-7a18.5 18.5 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.4 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="2" y1="2" x2="22" y2="22"/></svg></button>
                        </div>
                    </div>

                    <button type="submit" class="cc-btn-primary w-full">
                        Sign In
                    </button>
                </form>

                <p class="mt-lg text-center text-metadata text-text-secondary">
                    Forgot your password? <a href="{{ route('password.request') }}" class="font-semibold text-primary-teal hover:underline">Reset it by email</a>.
                    Still locked out? Contact your Admin.
                </p>
            </div>
        </div>
    </div>
    <script>
        (function () {
            var input = document.getElementById('password');
            var btn = document.getElementById('toggle-password');
            if (!input || !btn) return;
            btn.addEventListener('click', function () {
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                // Crossed-out eye = password hidden; open eye = password visible.
                btn.querySelector('.icon-eye').style.display = show ? '' : 'none';
                btn.querySelector('.icon-eye-off').style.display = show ? 'none' : '';
                btn.setAttribute('aria-pressed', show ? 'true' : 'false');
                btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                btn.setAttribute('title', show ? 'Hide password' : 'Show password');
                input.focus();
            });
            // Never submit the password while it is visible on screen.
            input.form.addEventListener('submit', function () { input.type = 'password'; });
        })();
    </script>
</body>
</html>
