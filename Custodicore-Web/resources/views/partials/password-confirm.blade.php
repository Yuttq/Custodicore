{{--
    Shared "enter your password to finish" modal.

    Included by BOTH staff layouts (layouts/admin = Tailwind + Vite,
    layouts/app = static public/css/styles.css), which share no CSS or JS —
    so this partial is deliberately self-contained (inline <style>/<script>,
    no Tailwind classes, no Vite).

    Usage: add  data-password-confirm="Message shown in the popup"  to any
    <form method="POST">. Submitting that form opens the popup; the typed
    password is added to the form as `current_password` and the form is then
    submitted for real. The server-side `reauth` middleware
    (App\Http\Middleware\ConfirmActorPassword) is what actually enforces it.

    $banner (bool, default false) — also show a dismissible notice after a
    rejected password. Layouts that already list $errors in their own flash
    modal (layouts/app) pass false so it isn't shown twice.
--}}
@php
    $showBanner = ($banner ?? false) && $errors->has('current_password') && ! old('_edit_id');
@endphp

<style>
    #cc-pw-confirm { border: 0; padding: 0; border-radius: 16px; width: min(420px, calc(100vw - 32px)); background: #FFFFFF; color: #111827; box-shadow: 0 20px 50px rgba(15, 23, 42, .25); font-family: inherit; }
    #cc-pw-confirm::backdrop { background: rgba(15, 23, 42, .55); }
    #cc-pw-confirm .ccpw-head { background: #0F3D7A; color: #FFFFFF; padding: 16px 24px; font-size: 16px; font-weight: 700; }
    #cc-pw-confirm .ccpw-body { padding: 24px; }
    #cc-pw-confirm .ccpw-msg { margin: 0 0 16px; font-size: 14px; line-height: 1.5; color: #374151; }
    #cc-pw-confirm label { display: block; margin-bottom: 8px; font-size: 13px; font-weight: 600; color: #111827; }
    #cc-pw-confirm input[type="password"], #cc-pw-confirm input[type="text"] { box-sizing: border-box; width: 100%; height: 48px; padding: 0 12px; border: 1px solid #E5E7EB; border-radius: 12px; font-size: 14px; background: #FFFFFF; color: #111827; }
    #cc-pw-confirm input:focus { outline: none; border-color: #0DA58A; box-shadow: 0 0 0 3px rgba(13, 165, 138, .2); }
    #cc-pw-confirm .ccpw-show { display: flex; align-items: center; gap: 8px; margin: 10px 0 0; font-size: 13px; font-weight: 400; color: #6B7280; cursor: pointer; }
    #cc-pw-confirm .ccpw-show input { width: 16px; height: 16px; margin: 0; accent-color: #0DA58A; }
    #cc-pw-confirm .ccpw-err { margin: 10px 0 0; font-size: 13px; color: #EF4444; }
    #cc-pw-confirm .ccpw-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 24px; }
    #cc-pw-confirm button { height: 44px; padding: 0 20px; border-radius: 12px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; }
    #cc-pw-confirm .ccpw-cancel { background: #FFFFFF; color: #374151; border: 1px solid #E5E7EB; }
    #cc-pw-confirm .ccpw-ok { background: #16A34A; color: #FFFFFF; border: 1px solid #16A34A; }
    #cc-pw-confirm .ccpw-ok:disabled { opacity: .6; cursor: not-allowed; }

    #cc-pw-banner { position: fixed; top: 16px; left: 50%; transform: translateX(-50%); z-index: 60; display: flex; align-items: center; gap: 12px; max-width: min(560px, calc(100vw - 32px)); padding: 12px 16px; background: #FFFFFF; border: 1px solid #EF4444; border-left-width: 4px; border-radius: 12px; box-shadow: 0 10px 30px rgba(15, 23, 42, .15); font-size: 14px; color: #111827; }
    #cc-pw-banner button { border: 0; background: none; font-size: 18px; line-height: 1; cursor: pointer; color: #6B7280; }
</style>

<dialog id="cc-pw-confirm" aria-labelledby="ccpw-title">
    <div class="ccpw-head" id="ccpw-title">Confirm with your password</div>
    <div class="ccpw-body">
        <p class="ccpw-msg" id="ccpw-msg">Enter your password to finish this change.</p>
        <label for="ccpw-input">Your password ({{ auth()->user()?->displayName() }})</label>
        <input type="password" id="ccpw-input" autocomplete="current-password" />
        <label class="ccpw-show"><input type="checkbox" id="ccpw-toggle" /> Show password</label>
        <p class="ccpw-err" id="ccpw-err" hidden>Enter your password to continue.</p>
        <div class="ccpw-actions">
            <button type="button" class="ccpw-cancel" id="ccpw-cancel">Cancel</button>
            <button type="button" class="ccpw-ok" id="ccpw-ok">Confirm &amp; Save</button>
        </div>
    </div>
</dialog>

@if ($showBanner)
    <div id="cc-pw-banner" role="alert">
        <span>{{ $errors->first('current_password') }}</span>
        <button type="button" aria-label="Dismiss" onclick="document.getElementById('cc-pw-banner').remove()">&times;</button>
    </div>
@endif

<script>
(function () {
    var dlg = document.getElementById('cc-pw-confirm');
    if (!dlg || typeof dlg.showModal !== 'function') return; // very old browser: server-side check still applies

    var input = document.getElementById('ccpw-input');
    var msg = document.getElementById('ccpw-msg');
    var err = document.getElementById('ccpw-err');
    var ok = document.getElementById('ccpw-ok');
    var toggle = document.getElementById('ccpw-toggle');
    var pending = null;

    // Capture phase on document: runs before any handler on the form itself
    // (including resources/js/app.js's data-confirm / button-disable logic),
    // so the form is held until the password has been entered.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.hasAttribute || !form.hasAttribute('data-password-confirm')) return;
        if (form.dataset.pwOk === '1') return;

        e.preventDefault();
        e.stopPropagation();

        pending = form;
        msg.textContent = form.getAttribute('data-password-confirm') || 'Enter your password to finish this change.';
        input.value = '';
        input.type = 'password';
        toggle.checked = false;
        err.hidden = true;
        ok.disabled = false;
        ok.textContent = 'Confirm & Save';
        dlg.showModal();
        input.focus();
    }, true);

    function confirmPassword() {
        if (!pending) return;
        if (!input.value) {
            err.hidden = false;
            input.focus();
            return;
        }

        var form = pending;
        var hidden = form.querySelector('input[name="current_password"]');
        if (!hidden) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'current_password';
            form.appendChild(hidden);
        }
        hidden.value = input.value;
        form.dataset.pwOk = '1';

        ok.disabled = true;
        ok.textContent = 'Saving…';
        form.submit(); // native submit: does not re-fire the submit event
    }

    ok.addEventListener('click', confirmPassword);
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); confirmPassword(); }
    });
    document.getElementById('ccpw-cancel').addEventListener('click', function () { dlg.close(); });
    toggle.addEventListener('change', function () { input.type = toggle.checked ? 'text' : 'password'; });
    dlg.addEventListener('close', function () {
        if (!ok.disabled) { pending = null; }
        input.value = '';
    });

    // Back/forward cache can restore a page whose form was already approved —
    // reset so the next submit asks for the password again.
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        document.querySelectorAll('form[data-password-confirm]').forEach(function (f) {
            delete f.dataset.pwOk;
            var h = f.querySelector('input[name="current_password"]');
            if (h) h.value = '';
        });
        ok.disabled = false;
        ok.textContent = 'Confirm & Save';
    });
})();
</script>
