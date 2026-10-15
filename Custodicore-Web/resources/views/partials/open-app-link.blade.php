{{--
    "Open the CustodiCore app" button for public success pages opened from an
    email (password reset, email verification). $href is the app's registered
    scheme (app.json "scheme", AndroidManifest intent filter) and must never
    carry a token, email or password. The host page styles .btn, .muted and
    .notice.

    @param string $href       e.g. custodicore://login
    @param string $fallback   always visible (also without JavaScript)
    @param string $notOpened  replaces $fallback if the app did not open
--}}
<p><a class="btn" id="open-app" href="{{ $href }}">Open the CustodiCore app</a></p>
<p class="muted" id="open-app-fallback" role="status">{{ $fallback }}</p>
<script>
    // A custom-scheme link silently does nothing where the app is
    // not installed (e.g. a computer). If this page is still in
    // front shortly after the tap, point the visitor to the fallback.
    (function () {
        var link = document.getElementById('open-app');
        var fallback = document.getElementById('open-app-fallback');
        if (!link || !fallback) return;
        link.addEventListener('click', function () {
            var left = false;
            var markLeft = function () { left = true; };
            document.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'hidden') markLeft();
            }, { once: true });
            window.addEventListener('pagehide', markLeft, { once: true });
            setTimeout(function () {
                if (left || document.visibilityState === 'hidden') return;
                fallback.className = 'notice';
                fallback.textContent = @json($notOpened);
            }, 2000);
        });
    })();
</script>
