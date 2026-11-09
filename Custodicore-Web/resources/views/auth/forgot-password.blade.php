<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Forgot Password · CustodiCore</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
@include('partials.favicon')
</head>
<body class="min-h-screen bg-primary-navy text-text-primary">
    <div class="flex min-h-screen items-center justify-center px-md py-xl">
        <div class="w-full max-w-md">
            <div class="mb-lg flex flex-col items-center text-center">
                <img src="{{ asset('images/bjmp-logo.png') }}" alt="Bureau of Jail Management and Penology logo"
                     class="mb-sm h-24 w-24 object-contain"
                     onerror="this.style.display='none'; document.getElementById('login-badge').style.display='flex';">
                <div id="login-badge" style="display:none;" class="mb-sm h-14 w-14 items-center justify-center rounded-card bg-white/10 text-card-title font-bold text-white">CC</div>
                <p class="text-page-title text-white">CustodiCore</p>
                <p class="text-status-label uppercase tracking-wide text-white/60">BJMP Facility Management System</p>
            </div>

            <div class="cc-card">
                <h1 class="mb-sm text-card-title">Forgot your password?</h1>
                <p class="mb-lg text-body text-text-secondary">
                    Enter the email address you use to sign in. If it belongs to an active staff account,
                    we'll email you a link to choose a new password.
                </p>

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

                <form method="POST" action="{{ route('password.email') }}" class="space-y-md">
                    @csrf

                    <div>
                        <label for="email" class="cc-label">Email Address</label>
                        <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                               maxlength="100" autocomplete="email" placeholder="e.g. juan.delacruz@gmail.com" class="cc-input" />
                    </div>

                    <button type="submit" class="cc-btn-primary w-full">
                        Send Reset Link
                    </button>
                </form>

                <p class="mt-lg text-center text-metadata text-text-secondary">
                    <a href="{{ route('login') }}" class="font-semibold text-primary-teal hover:underline">&larr; Back to Sign In</a>
                </p>
                <p class="mt-sm text-center text-metadata text-text-secondary">
                    No longer have access to that email? Contact your Admin.
                </p>
            </div>
        </div>
    </div>
</body>
</html>
