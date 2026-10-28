@extends('auth.mfa-layout')

@section('title', 'Backup codes')

@section('body')
    <h1 class="text-card-title">{{ $firstTime ? 'Two-step verification is on' : 'Your new backup codes' }}</h1>
    <p class="mb-md mt-xs text-body text-text-secondary">
        Save these backup codes somewhere safe. Each works <strong>once</strong> if you lose your phone.
        They are shown only now{{ $firstTime ? '' : ', and the old ones no longer work' }}.
    </p>

    <ul id="codes" class="grid grid-cols-2 gap-sm rounded-card border border-border bg-[#F8FAFC] p-md font-mono text-body tracking-wider">
        @foreach ($codes as $code)
            <li class="select-all">{{ $code }}</li>
        @endforeach
    </ul>

    <div class="mt-md flex flex-wrap gap-sm">
        <button type="button" id="dl" class="cc-btn-secondary">Download .txt</button>
        <button type="button" onclick="window.print()" class="cc-btn-secondary">Print</button>
    </div>

    <a href="{{ route('account.mfa.continue') }}" class="cc-btn-primary mt-lg block w-full text-center">I saved them — continue</a>

    <script>
        document.getElementById('dl').addEventListener('click', function () {
            var codes = Array.prototype.map.call(document.querySelectorAll('#codes li'), function (li) { return li.textContent.trim(); });
            var text = 'CustodiCore backup codes for ' + {!! json_encode($account->email, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!} + '\nEach code works once.\n\n' + codes.join('\n') + '\n';
            var a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
            a.download = 'custodicore-backup-codes.txt';
            document.body.appendChild(a); a.click(); a.remove();
        });
    </script>
@endsection
