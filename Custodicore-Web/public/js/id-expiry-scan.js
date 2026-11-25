/*
 * "Scan ID": reads the expiry date off a visitor's ID picture with OCR
 * (Tesseract.js, in the officer's browser; the picture never leaves the
 * computer) and fills in the ID expiry date box. The officer checks it
 * against the ID before saving; nothing is saved by the scan itself.
 *
 * Markup: a [data-id-scan] button with data-src (the ID picture URL), inside
 * a container that also holds [data-id-expiry-date], [data-id-no-expiry] and
 * a [data-id-scan-status] element.
 *
 * findExpiryDate(text, today) is the date finder, kept free of the page so
 * it can be tested on its own (tests/js/id-expiry-scan.test.mjs).
 */
(function (root) {
  'use strict';

  var TESSERACT_URL = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';

  var MONTHS = {
    JAN: 1, JANUARY: 1, FEB: 2, FEBRUARY: 2, MAR: 3, MARCH: 3, APR: 4, APRIL: 4, MAY: 5,
    JUN: 6, JUNE: 6, JUL: 7, JULY: 7, AUG: 8, AUGUST: 8, SEP: 9, SEPT: 9, SEPTEMBER: 9,
    OCT: 10, OCTOBER: 10, NOV: 11, NOVEMBER: 11, DEC: 12, DECEMBER: 12
  };

  // Labels printed next to the expiry on Philippine IDs (LTO license,
  // passport, PRC, UMID, postal ID ...).
  var EXPIRY_LABEL = /(EXPIR\w*|VALID\s*(UNTIL|UNTL|THRU|THROUGH|TO)|DATE\s*OF\s*EXP\w*|EXP\.?\s*DATE|\bEXP\b)/;
  // Labels of the other dates on an ID, never the expiry.
  var OTHER_LABEL = /(BIRTH|\bDOB\b|ISSU|REGISTRATION|APPLICATION)/;

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  function makeDate(y, m, d) {
    if (y < 100) y += 2000;
    if (m < 1 || m > 12 || d < 1 || d > 31) return null;
    var date = new Date(Date.UTC(y, m - 1, d));
    if (date.getUTCMonth() !== m - 1) return null; // e.g. Feb 30
    return y + '-' + pad(m) + '-' + pad(d);
  }

  // Common OCR slips inside numbers: O→0, I/l→1, S→5, B→8.
  function cleanDigits(s) {
    return s.replace(/(?<=\d)[O]|[O](?=\d)/g, '0').replace(/(?<=\d)[IL]|[IL](?=\d)/g, '1')
      .replace(/(?<=\d)S(?=\d)/g, '5').replace(/(?<=\d)B(?=\d)/g, '8');
  }

  /** Every date written in a line, in reading order, as YYYY-MM-DD. */
  function datesIn(line) {
    var found = [];
    var s = cleanDigits(line.toUpperCase());
    var m;

    // 2028/03/04, 2028-03-04, 2028.03.04 (LTO license prints year first)
    var ymd = /\b(\d{4})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{1,2})\b/g;
    while ((m = ymd.exec(s))) push(m.index, makeDate(+m[1], +m[2], +m[3]));

    // 03/04/2028 → March 4 (month first, the common Philippine order);
    // 25/03/2028 can only be day first.
    var mdy = /\b(\d{1,2})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{4})\b/g;
    while ((m = mdy.exec(s))) {
      var a = +m[1], b = +m[2];
      push(m.index, a > 12 ? makeDate(+m[3], b, a) : makeDate(+m[3], a, b));
    }

    // 04 MAR 2028, 4 March 2028
    var dmonY = /\b(\d{1,2})\s*([A-Z]{3,9})\.?\s*,?\s*(\d{4})\b/g;
    while ((m = dmonY.exec(s))) if (MONTHS[m[2]]) push(m.index, makeDate(+m[3], MONTHS[m[2]], +m[1]));

    // MAR 04 2028, March 4, 2028
    var monDY = /\b([A-Z]{3,9})\.?\s*(\d{1,2})\s*,?\s*(\d{4})\b/g;
    while ((m = monDY.exec(s))) if (MONTHS[m[1]]) push(m.index, makeDate(+m[3], MONTHS[m[1]], +m[2]));

    function push(at, date) { if (date) found.push({ at: at, date: date }); }

    return found.sort(function (x, y) { return x.at - y.at; }).map(function (f) { return f.date; });
  }

  /** Passport machine-readable zone: the expiry is the 6 digits after the sex. */
  function mrzExpiry(text) {
    var lines = text.toUpperCase().replace(/ /g, '').split(/\n/);
    for (var i = 0; i < lines.length; i++) {
      // TD3 line 2: number(9) check nationality(3) birth(6) check sex expiry(6)
      var m = /[A-Z0-9<]{9}[0-9<][A-Z<]{3}(\d{6})[0-9<]([MFX<])(\d{6})/.exec(lines[i]);
      if (m) {
        var e = m[3];
        var date = makeDate(2000 + +e.slice(0, 2), +e.slice(2, 4), +e.slice(4, 6));
        if (date) return date;
      }
    }
    return null;
  }

  /**
   * The ID's expiry date found in OCR text, or null.
   * Returns { date: 'YYYY-MM-DD', how: 'label' | 'mrz' | 'latest' }.
   *   label  — a date printed next to an expiry label ("Expiration Date")
   *   mrz    — read from a passport's machine-readable lines
   *   latest — no label found: the only date later than today (birth and
   *            issue dates are in the past); a weaker guess
   */
  function findExpiryDate(text, today) {
    if (!text) return null;
    today = today || new Date().toISOString().slice(0, 10);
    var lines = String(text).split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean);

    for (var i = 0; i < lines.length; i++) {
      var upper = lines[i].toUpperCase();
      var label = EXPIRY_LABEL.exec(upper);
      if (!label) continue;
      // The date after the label on the same line, else on the next line.
      var after = datesIn(upper.slice(label.index));
      if (after.length) return { date: after[0], how: 'label' };
      if (lines[i + 1] && !OTHER_LABEL.test(lines[i + 1].toUpperCase())) {
        var next = datesIn(lines[i + 1]);
        if (next.length) return { date: next[0], how: 'label' };
      }
    }

    var mrz = mrzExpiry(String(text));
    if (mrz) return { date: mrz, how: 'mrz' };

    var future = [];
    var afterOtherLabel = false; // "Date Issued" on one line, its date on the next
    lines.forEach(function (l) {
      var dates = datesIn(l);
      if (OTHER_LABEL.test(l.toUpperCase())) {
        afterOtherLabel = dates.length === 0;
        return;
      }
      var skip = afterOtherLabel;
      afterOtherLabel = false;
      if (skip) return;
      dates.forEach(function (d) { if (d > today && future.indexOf(d) === -1) future.push(d); });
    });
    return future.length === 1 ? { date: future[0], how: 'latest' } : null;
  }

  // ------------------------------------------------------------------ page

  var tesseract = null;
  function loadTesseract() {
    if (root.Tesseract) return Promise.resolve(root.Tesseract);
    if (!tesseract) {
      tesseract = new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = TESSERACT_URL;
        s.onload = function () { resolve(root.Tesseract); };
        s.onerror = function () { tesseract = null; reject(new Error('load')); };
        document.head.appendChild(s);
      });
    }
    return tesseract;
  }

  function niceDate(iso) {
    var p = iso.split('-');
    var names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return names[+p[1] - 1] + ' ' + +p[2] + ', ' + p[0];
  }

  function scan(button) {
    var box = button.closest('[data-id-scan-box]');
    var dateInput = box.querySelector('[data-id-expiry-date]');
    var noExpiry = box.querySelector('[data-id-no-expiry]');
    var status = box.querySelector('[data-id-scan-status]');
    function say(text, tone) {
      status.textContent = text;
      status.className = 'id-scan-status' + (tone ? ' is-' + tone : '');
    }

    button.disabled = true;
    say('Reading the ID… (the first scan takes a little longer)');

    fetch(button.dataset.src, { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error('file');
        return r.blob();
      })
      .then(function (blob) {
        if (!/^image\//.test(blob.type)) throw new Error('pdf');
        return loadTesseract().then(function (T) { return T.recognize(blob, 'eng'); });
      })
      .then(function (result) {
        var found = findExpiryDate(result.data.text);
        if (!found) {
          say("Couldn't read an expiry date. Type it from the ID, or tick \"No expiry date\".", 'warn');
          return;
        }
        if (noExpiry) noExpiry.checked = false;
        dateInput.disabled = false;
        dateInput.value = found.date;
        dateInput.dispatchEvent(new Event('change', { bubbles: true }));
        say((found.how === 'latest' ? 'Possible expiry (no "expiry" label found): ' : 'Scanned: expires ')
          + niceDate(found.date) + '. Check it against the ID before saving.', found.how === 'latest' ? 'warn' : 'ok');
      })
      .catch(function (e) {
        say(e && e.message === 'pdf'
          ? 'This ID was uploaded as a PDF, which cannot be scanned. Type the date from the ID.'
          : e && e.message === 'load'
            ? 'The scanner could not load (no internet?). Type the date from the ID.'
            : "Couldn't scan this ID. Type the date from the ID.", 'warn');
      })
      .then(function () { button.disabled = false; });
  }

  if (typeof document !== 'undefined') {
    document.addEventListener('click', function (e) {
      var button = e.target.closest && e.target.closest('[data-id-scan]');
      if (button) { e.preventDefault(); scan(button); }
    });
  }

  // loadTesseract is shared with the relationship document check (relationship-docs-scan.js).
  var api = { findExpiryDate: findExpiryDate, datesIn: datesIn, loadTesseract: loadTesseract };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  root.IdExpiryScan = api;
})(typeof window !== 'undefined' ? window : globalThis);
