<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In · CustodiCore</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-primary-navy text-text-primary">
    <div class="flex min-h-screen items-center justify-center px-md py-xl">
        <div class="w-full max-w-md">
            {{-- Brand mark — mirrors the sidebar badge on the staff dashboards --}}
            <div class="mb-lg flex flex-col items-center text-center">
                <div class="mb-sm flex h-14 w-14 items-center justify-center rounded-card bg-white/10 text-card-title font-bold text-white">CC</div>
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
                               autocomplete="username" placeholder="you@bjmp.gov.ph" class="cc-input" />
                    </div>

                    <div>
                        <label for="password" class="cc-label">Password</label>
                        <input type="password" id="password" name="password" required
                               autocomplete="current-password" placeholder="••••••••" class="cc-input" />
                    </div>

                    <button type="submit" class="cc-btn-primary w-full">
                        Sign In
                    </button>
                </form>

                <p class="mt-lg text-center text-metadata text-text-secondary">
                    Lost access to your account? Contact your Warden administrator to reset it.
                </p>
            </div>
        </div>
    </div>
</body>
</html>
