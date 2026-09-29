import './bootstrap';

// Bundled via npm/Vite instead of a <script src="https://cdnjs..."> tag, so
// the dashboard's charts aren't at the mercy of an external CDN being
// reachable (a firewall, an ad blocker, or just being offline will silently
// leave the canvases blank if Chart.js has to load from the internet).
import Chart from 'chart.js/auto';

window.Chart = Chart;

/**
 * Confirmation + basic error-prevention for every state-changing action in
 * the app (sign out, deactivate an account, check out a visitor, etc.).
 *
 * Applies automatically to every <form method="POST"> on the page — that
 * covers every mutating action here (Laravel always physically POSTs and
 * spoofs PATCH/PUT/DELETE via a hidden _method field, so this one check
 * catches all of them). Plain GET forms (the visitor-lookup search box)
 * are read-only and deliberately skipped — nothing to confirm or guard
 * there.
 *
 * Two independent safeguards, either of which satisfies "every action
 * needs confirmation or error prevention":
 *   1. Confirmation — a form with a data-confirm="..." attribute shows
 *      that question via window.confirm() before submitting. Cancel stops
 *      the request entirely. Used only on actions with real consequences
 *      (signing out, deactivating an account, checking a visitor out).
 *   2. Error prevention — every mutating form's submit button is disabled
 *      the instant it's clicked, so a double-click or an impatient extra
 *      tap can't fire the same action twice (e.g. registering the same
 *      officer, or checking the same visitor in, two times over).
 */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form').forEach((form) => {
        const method = (form.getAttribute('method') || 'get').toLowerCase();
        if (method !== 'post') return;

        form.addEventListener('submit', (event) => {
            const question = form.getAttribute('data-confirm');
            if (question && !window.confirm(question)) {
                event.preventDefault();
                return;
            }

            const submitButton = form.querySelector('button[type="submit"], input[type="submit"]');
            if (submitButton && !submitButton.disabled) {
                submitButton.disabled = true;
                submitButton.classList.add('opacity-60', 'cursor-not-allowed');
            }
        });
    });
});
