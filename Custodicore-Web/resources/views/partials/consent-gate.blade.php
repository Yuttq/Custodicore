{{--
    Terms & Conditions + Privacy Policy consent gate for the web registration
    forms (Admin "Register Officer" modal, Record Officer "Register New PDL").

    Place INSIDE the <form>, before the detail fields, and wrap the detail
    fields + submit button in  <fieldset id="{{ $target }}">.  The fieldset
    stays disabled (greyed out, nothing can be typed) until BOTH boxes are
    ticked — so consent comes before any personal details are entered. The
    server re-checks `accepted_terms` / `accepted_privacy`, so this is a UX
    gate, not the only one.

    Params:  $target  id of the fieldset to unlock
             $id      unique prefix when several gates share a page
             $subject who is being registered, e.g. "the officer"

    Self-contained (inline CSS/JS) because the two staff layouts share no
    stylesheet. Wording lives in config/legal.php — one source for web + app.
--}}
@php
    $gid = $id ?? 'consent';
    $who = $subject ?? 'the person being registered';
@endphp

<div class="ccg" id="{{ $gid }}-box">
    <p class="ccg-title">Step 1 — Terms &amp; Privacy</p>
    <p class="ccg-sub">
        Confirm that {{ $who }} has been shown, and agrees to, both documents below.
        The details form unlocks once both are ticked.
    </p>

    <label class="ccg-row">
        <input type="checkbox" name="accepted_terms" value="1" @checked(old('accepted_terms')) />
        <span>I have read and accept the
            <a href="{{ route('legal.terms') }}" target="_blank" rel="noopener">Terms &amp; Conditions</a>.</span>
    </label>
    <label class="ccg-row">
        <input type="checkbox" name="accepted_privacy" value="1" @checked(old('accepted_privacy')) />
        <span>I have read and accept the
            <a href="{{ route('legal.privacy') }}" target="_blank" rel="noopener">Privacy Policy</a>
            (Data Privacy Act of 2012).</span>
    </label>
    <p class="ccg-version">Version {{ config('legal.version') }}</p>
</div>

<style>
    .ccg { margin: 0 0 16px; padding: 16px; border: 1px solid #E5E7EB; border-radius: 12px; background: #F8FAFC; }
    .ccg-title { margin: 0 0 4px; font-size: 14px; font-weight: 700; color: #0F3D7A; }
    .ccg-sub { margin: 0 0 12px; font-size: 13px; line-height: 1.5; color: #6B7280; }
    .ccg-row { display: flex; align-items: flex-start; gap: 10px; margin: 0 0 8px; font-size: 13.5px; line-height: 1.5; color: #111827; cursor: pointer; }
    .ccg .ccg-row input[type="checkbox"] { flex: none; width: 18px !important; height: 18px !important; margin: 2px 0 0 !important; padding: 0 !important; accent-color: #0DA58A; }
    .ccg a { color: #2563EB; font-weight: 600; text-decoration: underline; }
    .ccg-version { margin: 4px 0 0; font-size: 12px; color: #6B7280; }
</style>

<script>
(function () {
    var box = document.getElementById(@json($gid . '-box'));
    var fs = document.getElementById(@json($target));
    if (!box || !fs) return;

    var boxes = box.querySelectorAll('input[type="checkbox"]');
    var form = box.closest('form');

    function sync() {
        var ok = Array.prototype.every.call(boxes, function (c) { return c.checked; });
        fs.disabled = !ok;
        fs.style.opacity = ok ? '' : '.45';
        if (form) {
            form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = !ok; });
        }
    }

    Array.prototype.forEach.call(boxes, function (c) { c.addEventListener('change', sync); });
    sync();
})();
</script>
