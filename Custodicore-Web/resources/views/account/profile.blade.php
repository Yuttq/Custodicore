<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Profile · CustodiCore</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
@php
    $photo = $profile?->photoUrl();
    $name = $account->displayName();
    $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
    $initials = strtoupper(mb_substr($words[0], 0, 1) . (count($words) > 1 ? mb_substr($words[count($words) - 1], 0, 1) : ''));
@endphp
<body class="min-h-screen bg-background text-text-primary">
    {{-- Slim header: logo, and the way back to whichever dashboard this officer uses. --}}
    <header class="flex items-center gap-sm border-b border-border bg-white px-lg py-sm">
        <img src="{{ asset('images/bjmp-logo.png') }}" alt="" class="h-9 w-9 object-contain" onerror="this.style.display='none'">
        <p class="text-card-title text-primary-navy">CustodiCore</p>
        <a href="{{ route('home') }}" class="cc-btn-secondary ml-auto">Back to dashboard</a>
    </header>

    <main class="mx-auto max-w-3xl space-y-md px-md py-lg">
        <h1 class="text-page-title">My Profile</h1>

        @if (session('status'))
            <div class="cc-card border-l-4 border-l-info">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="rounded-sm border-l-4 border-l-danger bg-danger/5 p-md text-body text-danger">
                {{ $errors->first() }}
            </div>
        @endif

        @if (! $profile)
            <div class="cc-card">
                <p class="text-body">This account has no staff profile, so a picture can't be added. Ask the Warden to check your account.</p>
            </div>
        @else
            <div class="cc-card">
                <p class="text-card-title">Profile Picture</p>
                <p class="mt-xs text-metadata text-text-secondary">
                    Shown next to your name in the sidebar and in the staff list. Only signed-in staff can see it.
                </p>

                <div class="mt-md flex flex-col gap-lg sm:flex-row sm:items-center">
                    {{-- Live preview: shows the chosen file before it is saved. --}}
                    <div class="relative h-40 w-40 shrink-0 overflow-hidden rounded-full border border-border bg-primary-navy" id="photo-preview-frame">
                        <img id="photo-preview" src="{{ $photo }}" alt="Your profile picture"
                             class="{{ $photo ? '' : 'hidden' }} h-full w-full object-cover">
                        <div id="photo-initials" class="{{ $photo ? 'hidden' : 'flex' }} h-full w-full items-center justify-center text-[48px] font-bold text-white">{{ $initials }}</div>
                    </div>

                    <div class="min-w-0 flex-1">
                        <form method="POST" action="{{ route('account.photo.update') }}" enctype="multipart/form-data" id="photo-form">
                            @csrf
                            <label for="photo" class="cc-label">Choose a picture</label>
                            <input type="file" id="photo" name="photo" required accept="image/jpeg,image/png,image/webp"
                                   class="mt-xs block w-full text-body file:mr-sm file:h-[38px] file:cursor-pointer file:rounded-button file:border file:border-border file:bg-white file:px-md file:text-body file:font-semibold">
                            <p class="mt-xs text-metadata text-text-secondary">JPG, PNG or WEBP, up to 2 MB, at least 100 x 100 pixels. A clear, front-facing photo works best.</p>
                            <p id="photo-client-error" class="mt-xs hidden text-metadata text-danger"></p>

                            <div class="mt-md flex flex-wrap gap-sm">
                                <button type="submit" id="photo-save" class="cc-btn-primary" disabled>Save picture</button>
                            </div>
                        </form>

                        @if ($photo)
                            <form method="POST" action="{{ route('account.photo.destroy') }}" class="mt-sm"
                                  onsubmit="return confirm('Remove your profile picture?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="cc-btn-secondary">Remove picture</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>

            <div class="cc-card">
                <p class="text-card-title">My Details</p>
                <p class="mt-xs text-metadata text-text-secondary">Read-only. To change these, ask the Warden.</p>
                <dl class="mt-md grid grid-cols-1 gap-md sm:grid-cols-2">
                    @foreach ([
                        'Name' => $name,
                        'Role' => $account->role?->role_name ?? '—',
                        'Employee #' => $profile->employee_number ?? '—',
                        'Mobile' => $profile->contact_number ?: '—',
                        'Sign-in Email' => $account->email,
                        'Username' => $account->username,
                    ] as $label => $value)
                        <div>
                            <dt class="text-section-label uppercase text-text-secondary">{{ $label }}</dt>
                            <dd class="mt-xs break-words text-body font-semibold">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endif
    </main>

    <script>
        (function () {
            var input = document.getElementById('photo');
            if (!input) return;

            var save = document.getElementById('photo-save');
            var img = document.getElementById('photo-preview');
            var initials = document.getElementById('photo-initials');
            var err = document.getElementById('photo-client-error');
            var MAX = 2 * 1024 * 1024;
            var ok = ['image/jpeg', 'image/png', 'image/webp'];

            function problem(msg) {
                err.textContent = msg;
                err.classList.remove('hidden');
                save.disabled = true;
            }

            input.addEventListener('change', function () {
                err.classList.add('hidden');
                var file = input.files && input.files[0];
                if (!file) { save.disabled = true; return; }

                // Friendly early checks; the server checks everything again.
                if (ok.indexOf(file.type) === -1) { return problem('Please choose a JPG, PNG or WEBP picture.'); }
                if (file.size > MAX) { return problem('That picture is larger than 2 MB. Choose a smaller one.'); }

                var url = URL.createObjectURL(file);
                img.src = url;
                img.classList.remove('hidden');
                initials.classList.add('hidden');
                initials.classList.remove('flex');
                save.disabled = false;
            });
        })();
    </script>
</body>
</html>
