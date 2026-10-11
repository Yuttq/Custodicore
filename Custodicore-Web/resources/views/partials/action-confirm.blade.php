{{--
    Shared "Are you sure?" popup for layouts/app (Record Officer pages).

    Same attribute as the admin layout's resources/js/app.js, but a styled
    dialog instead of window.confirm(). Self-contained (inline style/script)
    because layouts/app loads no Vite assets.

    Usage on any <form method="POST">:
      data-confirm="Question shown in the popup"      (required)
      data-confirm-title="Reject document"            (optional heading)
      data-confirm-ok="Reject"                        (optional button text)
      data-confirm-danger                             (optional: red button)

    The browser's own field checks (required, maxlength, ...) run first; the
    popup only appears for a form that is otherwise ready to submit. After
    confirming, the submit button is disabled so the action can't be sent
    twice. Forms using data-password-confirm are left to that popup.
--}}
<style>
    #cc-confirm { border: 0; padding: 0; border-radius: 16px; width: min(420px, calc(100vw - 32px)); background: #FFFFFF; color: #111827; box-shadow: 0 20px 50px rgba(15, 23, 42, .25); font-family: inherit; }
    #cc-confirm::backdrop { background: rgba(15, 23, 42, .55); }
    #cc-confirm .ccc-head { background: #0F3D7A; color: #FFFFFF; padding: 16px 24px; font-size: 16px; font-weight: 700; }
    #cc-confirm .ccc-body { padding: 24px; }
    #cc-confirm .ccc-msg { margin: 0; font-size: 14px; line-height: 1.5; color: #374151; white-space: pre-line; }
    #cc-confirm .ccc-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 24px; }
    #cc-confirm button { height: 44px; padding: 0 20px; border-radius: 12px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: inherit; }
    #cc-confirm .ccc-cancel { background: #FFFFFF; color: #374151; border: 1px solid #E5E7EB; }
    #cc-confirm .ccc-ok { background: #16A34A; color: #FFFFFF; border: 1px solid #16A34A; }
    #cc-confirm .ccc-ok.is-danger { background: #DC2626; border-color: #DC2626; }
    #cc-confirm.has-summary { width: min(560px, calc(100vw - 32px)); }

    /* Review list: the values the officer entered (shared with the password popup). */
    .cc-review { margin: 16px 0 0; border: 1px solid #E5E7EB; border-radius: 12px; max-height: min(50vh, 420px); overflow-y: auto; }
    .cc-review-title { margin: 16px 0 0; font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #0F3D7A; }
    .cc-review-row { display: grid; grid-template-columns: minmax(110px, 38%) 1fr; gap: 12px; padding: 9px 14px; border-top: 1px solid #E5E7EB; font-size: 13.5px; }
    .cc-review-row:first-child { border-top: 0; }
    .cc-review-row dt { color: #6B7280; font-weight: 600; }
    .cc-review-row dd { margin: 0; color: #111827; white-space: pre-wrap; word-break: break-word; }
    .cc-review-row .cc-empty { color: #9CA3AF; }
    .cc-review-row .cc-old { color: #9CA3AF; text-decoration: line-through; margin-right: 6px; }
    .cc-review-row .cc-arrow { color: #6B7280; margin-right: 6px; }
    .cc-review-row .cc-review-img { display: block; margin-top: 8px; width: 96px; height: 96px; object-fit: cover; border-radius: 10px; border: 1px solid #E5E7EB; }
    @media (max-width: 480px) { .cc-review-row { grid-template-columns: 1fr; gap: 2px; } }
</style>

<dialog id="cc-confirm" aria-labelledby="ccc-title">
    <div class="ccc-head" id="ccc-title">Please confirm</div>
    <div class="ccc-body">
        <p class="ccc-msg" id="ccc-msg"></p>
        <p class="cc-review-title" id="ccc-review-title" hidden>Please review the details</p>
        <dl class="cc-review" id="ccc-review" hidden></dl>
        <div class="ccc-actions">
            <button type="button" class="ccc-cancel" id="ccc-cancel">Cancel</button>
            <button type="button" class="ccc-ok" id="ccc-ok">Confirm</button>
        </div>
    </div>
</dialog>

<script>
/**
 * Review summary of a form's fields, used by this popup and by the password
 * popup (partials/password-confirm). Label comes from the field's
 * data-label, its .field-m <label>, a wrapping <label>, or its placeholder.
 *   ccFormSummary(form)  → [{label, value}]           every filled-in field
 *   ccFormChanges(form)  → [{label, from, to}]        only edited fields
 */
(function () {
    var SKIP_TYPES = ['hidden', 'submit', 'button', 'reset', 'password'];

    function clean(text) {
        return String(text || '').replace(/\s*\((optional|live-in partners)\)\s*/i, '').replace(/\s+/g, ' ').trim();
    }

    function labelFor(el) {
        if (el.dataset.label) return el.dataset.label;
        var field = el.closest('.field-m');
        var lbl = field && field.querySelector(':scope > label');
        if (lbl) return clean(lbl.textContent);
        var wrap = el.closest('label');
        if (wrap) return clean(wrap.textContent);
        if (el.id) {
            var forLbl = document.querySelector('label[for="' + el.id + '"]');
            if (forLbl) return clean(forLbl.textContent);
        }
        if (el.placeholder) return clean(el.placeholder);
        return clean(el.name.replace(/_/g, ' ').replace(/^\w/, function (c) { return c.toUpperCase(); }));
    }

    function prettyDate(v) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return v;
        var d = new Date(v + 'T00:00:00Z');
        return isNaN(d) ? v : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
    }

    function display(el, useDefault) {
        if (el.tagName === 'SELECT') {
            var opt = useDefault
                ? Array.prototype.find.call(el.options, function (o) { return o.defaultSelected; }) || el.options[0]
                : el.options[el.selectedIndex];
            return opt && opt.value !== '' ? clean(opt.text) : '';
        }
        if (el.type === 'checkbox') return (useDefault ? el.defaultChecked : el.checked) ? 'Yes' : 'No';
        // File inputs start empty; show the chosen file's name.
        if (el.type === 'file') return useDefault ? '' : (el.files && el.files[0] ? el.files[0].name : '');
        var v = useDefault ? el.defaultValue : el.value;
        return el.type === 'date' ? prettyDate(v) : String(v || '').trim();
    }

    // One entry per field; radio groups collapse to their checked option.
    function fields(form) {
        var out = [], seenRadio = {};
        Array.prototype.forEach.call(form.elements, function (el) {
            if (!el.name || el.disabled && el.type !== 'radio' || SKIP_TYPES.indexOf(el.type) !== -1) return;
            if (el.name === '_token' || el.name === '_method') return;
            if (el.type === 'radio') {
                if (seenRadio[el.name]) return;
                seenRadio[el.name] = true;
                var group = form.querySelectorAll('input[type="radio"][name="' + el.name + '"]');
                var current = Array.prototype.find.call(group, function (r) { return r.checked; });
                var initial = Array.prototype.find.call(group, function (r) { return r.defaultChecked; });
                out.push({ label: labelFor(el), value: current ? current.value : '', initial: initial ? initial.value : '' });
                return;
            }
            // Fields hidden by the page (e.g. options that don't apply) are left out.
            if (el.getClientRects().length === 0) return;
            var entry = { label: labelFor(el), value: display(el, false), initial: display(el, true) };
            if (el.type === 'file' && el.files && el.files[0] && /^image\//.test(el.files[0].type)) {
                entry.image = URL.createObjectURL(el.files[0]);
            }
            out.push(entry);
        });
        return out;
    }

    window.ccFormSummary = function (form) {
        return fields(form).map(function (f) { return { label: f.label, value: f.value, image: f.image }; });
    };

    window.ccFormChanges = function (form) {
        return fields(form)
            .filter(function (f) { return f.value !== f.initial; })
            .map(function (f) { return { label: f.label, from: f.initial, to: f.value, image: f.image }; });
    };

    // Fills a <dl> with review rows; returns false when there is nothing to show.
    window.ccRenderReview = function (dl, rows, changes) {
        dl.innerHTML = '';
        rows.forEach(function (r) {
            var row = document.createElement('div');
            row.className = 'cc-review-row';
            var dt = document.createElement('dt');
            dt.textContent = r.label;
            var dd = document.createElement('dd');
            function value(text, cls) {
                var span = document.createElement('span');
                if (text) { span.textContent = text; if (cls) span.className = cls; }
                else { span.textContent = '—'; span.className = 'cc-empty'; }
                return span;
            }
            if (changes) {
                dd.appendChild(value(r.from, 'cc-old'));
                var arrow = document.createElement('span');
                arrow.className = 'cc-arrow';
                arrow.textContent = '→';
                dd.appendChild(arrow);
                dd.appendChild(value(r.to));
            } else {
                dd.appendChild(value(r.value));
            }
            if (r.image) {
                var img = document.createElement('img');
                img.className = 'cc-review-img';
                img.alt = r.label;
                img.src = r.image;
                dd.appendChild(img);
            }
            row.appendChild(dt);
            row.appendChild(dd);
            dl.appendChild(row);
        });
        return rows.length > 0;
    };
})();

(function () {
    var dlg = document.getElementById('cc-confirm');
    var title = document.getElementById('ccc-title');
    var msg = document.getElementById('ccc-msg');
    var ok = document.getElementById('ccc-ok');
    var pending = null;
    var pendingSubmitter = null;

    function lock(form, submitter) {
        var btn = submitter || form.querySelector('button[type="submit"], button:not([type])');
        if (btn) { btn.disabled = true; btn.dataset.ccLabel = btn.textContent; btn.textContent = 'Saving…'; }
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.hasAttribute || !form.hasAttribute('data-confirm')) return;
        if (form.hasAttribute('data-password-confirm')) return;

        // Old browser without <dialog>: fall back to the native confirm.
        if (!dlg || typeof dlg.showModal !== 'function') {
            if (!window.confirm(form.getAttribute('data-confirm'))) { e.preventDefault(); return; }
            lock(form, e.submitter);
            return;
        }

        e.preventDefault();
        e.stopPropagation();
        pending = form;
        pendingSubmitter = e.submitter || null;
        title.textContent = form.getAttribute('data-confirm-title') || 'Please confirm';
        msg.textContent = form.getAttribute('data-confirm');
        ok.textContent = form.getAttribute('data-confirm-ok') || 'Confirm';
        ok.classList.toggle('is-danger', form.hasAttribute('data-confirm-danger'));

        // Review of what was entered (forms with fields only).
        var review = document.getElementById('ccc-review');
        var hasRows = window.ccRenderReview(review, window.ccFormSummary(form), false);
        review.hidden = !hasRows;
        document.getElementById('ccc-review-title').hidden = !hasRows;
        dlg.classList.toggle('has-summary', hasRows);

        dlg.showModal();
        ok.focus();
    }, true);

    ok.addEventListener('click', function () {
        if (!pending) return;
        var form = pending;
        pending = null;
        dlg.close();
        lock(form, pendingSubmitter);
        // Keep the clicked button's name/value (e.g. decision=eligible).
        if (pendingSubmitter && pendingSubmitter.name) {
            var h = document.createElement('input');
            h.type = 'hidden'; h.name = pendingSubmitter.name; h.value = pendingSubmitter.value;
            form.appendChild(h);
        }
        form.submit(); // native submit: does not re-fire the submit event
    });
    document.getElementById('ccc-cancel').addEventListener('click', function () { pending = null; dlg.close(); });

    // Back/forward cache can restore a page with disabled buttons.
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        document.querySelectorAll('form[data-confirm] button[disabled][data-cc-label]').forEach(function (b) {
            b.disabled = false; b.textContent = b.dataset.ccLabel;
        });
    });
})();
</script>
