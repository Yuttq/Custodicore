/*
 * "Read PSA documents" on the Account Review card: reads the visitor's PSA
 * certificates with OCR (Tesseract.js, in the officer's browser; files never
 * leave the computer), looks for the visitor's and the PDL's names on them,
 * and suggests the relationship they show. The suggestion is pre-selected in
 * the card's Relationship box; the officer checks the documents and approves
 * as usual. Nothing is approved by the check itself.
 *
 *   PDL's birth certificate names the visitor (as a parent)   → parent
 *   Visitor's birth certificate names the PDL (as a parent)   → child
 *   Both birth certificates name the same parent             → sibling
 *   Marriage certificate names both                          → spouse
 *   Child's birth certificate names both (as its parents)    → live-in partner
 *
 * analyseDocuments() is the matching logic, kept free of the page so it can
 * be tested on its own (tests/js/relationship-docs-scan.test.mjs).
 */
(function (root) {
  'use strict';

  var SUFFIXES = ['JR', 'SR', 'II', 'III', 'IV', 'V'];
  // Words printed on PSA forms, never part of a person's name.
  var FORM_WORDS = ['REPUBLIC', 'PHILIPPINES', 'PHILIPPINE', 'STATISTICS', 'AUTHORITY', 'CERTIFICATE', 'LIVE', 'BIRTH',
    'MARRIAGE', 'NAME', 'FIRST', 'MIDDLE', 'LAST', 'MOTHER', 'FATHER', 'MAIDEN', 'CHILD', 'HUSBAND', 'WIFE', 'CITY',
    'PROVINCE', 'MUNICIPALITY', 'PLACE', 'DATE', 'CIVIL', 'REGISTRAR', 'REGISTRY', 'OFFICE', 'NATIONAL', 'SEX',
    'CITIZENSHIP', 'RELIGION', 'RESIDENCE', 'OCCUPATION', 'FILIPINO', 'ROMAN', 'CATHOLIC', 'MALE', 'FEMALE', 'THE', 'AND', 'OF'];

  function words(text) {
    return String(text || '').toUpperCase()
      .normalize('NFD').replace(/[̀-ͯ]/g, '') // Ñ → N, É → E
      .replace(/[^A-Z\s]/g, ' ').split(/\s+/).filter(Boolean);
  }

  // OCR slips: one wrong, missing or extra letter in a word of 4+ letters.
  function close(a, b) {
    if (a === b) return true;
    if (Math.min(a.length, b.length) < 4 || Math.abs(a.length - b.length) > 1) return false;
    var prev = [], cur, i, j;
    for (j = 0; j <= b.length; j++) prev[j] = j;
    for (i = 1; i <= a.length; i++) {
      cur = [i];
      for (j = 1; j <= b.length; j++) {
        cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
      }
      prev = cur;
    }
    return prev[b.length] <= 1;
  }

  /**
   * A person as the system knows them: first name, middle name(s), last
   * name. From the parts when stored, else from the full name ("Juan Santos
   * Dela Cruz Jr." → first JUAN, last CRUZ, middle SANTOS DELA).
   */
  function person(p) {
    var full = words(p.full).filter(function (w) { return SUFFIXES.indexOf(w) === -1; });
    var first = words(p.first)[0] || full[0] || '';
    var lastWords = words(p.last);
    var last = lastWords[lastWords.length - 1] || full[full.length - 1] || '';
    var middle = full.filter(function (w) { return w !== first && w !== last && words(p.first).indexOf(w) === -1 && lastWords.indexOf(w) === -1; });
    return { first: first, last: last, middle: middle, label: String(p.full || [p.first, p.last].join(' ')).trim() };
  }

  /**
   * Whether the person's name is written in the text: their first name, and
   * within a few words (either side) their last name or a middle name (a
   * married woman's middle name is her maiden surname, which her children's
   * birth certificates use).
   */
  function appears(text, who) {
    var w = Array.isArray(text) ? text : words(text);
    if (!who.first || !who.last) return false;
    var others = [who.last].concat(who.middle);
    for (var i = 0; i < w.length; i++) {
      if (!close(w[i], who.first)) continue;
      for (var j = Math.max(0, i - 6); j <= Math.min(w.length - 1, i + 6); j++) {
        if (j === i) continue;
        for (var k = 0; k < others.length; k++) if (close(w[j], others[k])) return true;
      }
    }
    return false;
  }

  /**
   * Two birth certificates show a shared parent: both name someone (not the
   * visitor or the PDL) with the family surname next to the same first name.
   */
  function shareParent(textA, textB, surnames, exclude) {
    function parents(text) {
      var w = words(text), found = [];
      for (var i = 0; i < w.length; i++) {
        if (!surnames.some(function (s) { return close(w[i], s); })) continue;
        for (var j = Math.max(0, i - 3); j <= Math.min(w.length - 1, i + 3); j++) {
          var name = w[j];
          if (j === i || name.length < 3 || FORM_WORDS.indexOf(name) !== -1) continue;
          if (exclude.some(function (x) { return close(name, x); })) continue;
          found.push(name);
        }
      }
      return found;
    }
    var a = parents(textA), b = parents(textB);
    return a.some(function (x) { return b.some(function (y) { return close(x, y); }); });
  }

  var LABELS = {
    spouse: 'Spouse', parent: 'Parent', child: 'Child', sibling: 'Sibling', live_in_partner: 'Live-in partner'
  };

  /**
   * docs: { key: OCR text } for marriage_certificate, pdl_birth_certificate,
   * visitor_birth_certificate, child_birth_certificate (any subset).
   * Returns {
   *   relationship: 'spouse' | 'parent' | 'child' | 'sibling' | 'live_in_partner' | null,
   *   reason: why, for the officer,
   *   documents: { key: 'match' | 'no_match' | 'unreadable' },
   * }
   */
  function analyseDocuments(docs, visitorName, pdlName) {
    var visitor = person(visitorName), pdl = person(pdlName);
    var status = {}, has = {};
    Object.keys(docs).forEach(function (key) {
      var text = docs[key] || '';
      if (words(text).length < 8) { status[key] = 'unreadable'; return; }
      has[key] = { visitor: appears(text, visitor), pdl: appears(text, pdl) };
    });

    function mark(key, ok) { if (has[key]) status[key] = ok ? 'match' : 'no_match'; }
    var result = null;

    if (has.marriage_certificate) {
      var married = has.marriage_certificate.visitor && has.marriage_certificate.pdl;
      mark('marriage_certificate', married);
      if (married) result = result || { relationship: 'spouse', reason: 'The marriage certificate names both ' + visitor.label + ' and ' + pdl.label + '.' };
    }
    if (has.child_birth_certificate) {
      var together = has.child_birth_certificate.visitor && has.child_birth_certificate.pdl;
      mark('child_birth_certificate', together);
      if (together && !result) result = { relationship: 'live_in_partner', reason: "The child's birth certificate names both " + visitor.label + ' and ' + pdl.label + ' as its parents.' };
    }
    var pdlCert = has.pdl_birth_certificate, visitorCert = has.visitor_birth_certificate;
    if (pdlCert && pdlCert.pdl && pdlCert.visitor && !result) {
      result = { relationship: 'parent', reason: "The PDL's birth certificate names " + visitor.label + ' as a parent.' };
    }
    if (visitorCert && visitorCert.visitor && visitorCert.pdl && !result) {
      result = { relationship: 'child', reason: "The visitor's birth certificate names " + pdl.label + ' as a parent.' };
    }
    if (pdlCert && visitorCert && pdlCert.pdl && visitorCert.visitor && !result
      && shareParent(docs.pdl_birth_certificate, docs.visitor_birth_certificate, [visitor.last, pdl.last], [visitor.first, pdl.first])) {
      result = { relationship: 'sibling', reason: 'Both birth certificates name the same parent.' };
    }
    if (pdlCert) mark('pdl_birth_certificate', pdlCert.pdl && (!result || result.relationship !== 'child'));
    if (visitorCert) mark('visitor_birth_certificate', visitorCert.visitor);

    return {
      relationship: result ? result.relationship : null,
      label: result ? LABELS[result.relationship] : null,
      reason: result ? result.reason : 'The documents read do not show how ' + visitor.label + ' and ' + pdl.label + ' are related. Check them manually.',
      documents: status
    };
  }

  // ------------------------------------------------------------------ page

  function readText(url) {
    return fetch(url, { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('file'); return r.blob(); })
      .then(function (blob) {
        if (!/^image\//.test(blob.type)) return '';
        return root.IdExpiryScan.loadTesseract().then(function (T) { return T.recognize(blob, 'eng'); })
          .then(function (res) { return res.data.text; });
      });
  }

  // The PDL on the card: linked already, or the one chosen in the PDL box ("Name (PDL-…)").
  function pdlOnCard(box, data) {
    if (data.pdl) return data.pdl;
    var panel = box.closest('[data-approve-panel]');
    var input = panel && panel.querySelector('.pdl-search-box input[type="text"]');
    var hidden = panel && panel.querySelector('[data-pdl-id]');
    if (!input || !hidden || !hidden.value) return null;
    return { full: input.value.replace(/\s*\([^)]*\)\s*$/, '') };
  }

  function check(button) {
    var box = button.closest('[data-doc-check]');
    var data = JSON.parse(box.querySelector('[data-doc-check-data]').textContent);
    var out = box.querySelector('[data-doc-check-result]');
    var pdl = pdlOnCard(box, data);
    if (!pdl) {
      out.innerHTML = '';
      out.appendChild(line('Choose the PDL first, then read the documents.', 'warn'));
      return;
    }

    button.disabled = true;
    out.innerHTML = '';
    out.appendChild(line('Reading ' + data.documents.length + ' document(s)… (the first read takes a little longer)'));

    var texts = {};
    data.documents.reduce(function (chain, doc) {
      return chain.then(function () {
        return readText(doc.url).then(function (t) { texts[doc.key] = t; }, function () { texts[doc.key] = ''; });
      });
    }, Promise.resolve()).then(function () {
      var result = analyseDocuments(texts, data.visitor, pdl);
      out.innerHTML = '';
      data.documents.forEach(function (doc) {
        var s = result.documents[doc.key];
        out.appendChild(line(doc.label + ': ' + ({ match: 'names match', no_match: "names don't match: check it", unreadable: "couldn't read (photo unclear or a PDF): check it" })[s],
          s === 'match' ? 'ok' : 'warn'));
      });
      out.appendChild(line((result.relationship ? 'Suggested relationship: ' + result.label + '. ' : '') + result.reason,
        result.relationship ? 'ok' : 'warn', true));

      var select = box.closest('[data-approve-panel]').querySelector('[data-rel-type]');
      if (result.relationship && select && select.querySelector('option[value="' + result.relationship + '"]')) {
        select.value = result.relationship;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        out.appendChild(line('Set in the Relationship box. Check the documents, then Approve.', 'ok'));
      }
    }).catch(function () {
      out.innerHTML = '';
      out.appendChild(line('The reader could not load (no internet?). Check the documents manually.', 'warn'));
    }).then(function () { button.disabled = false; });
  }

  function line(text, tone, strong) {
    var li = document.createElement('li');
    li.className = 'doc-check-line' + (tone ? ' is-' + tone : '');
    if (strong) li.style.fontWeight = '700';
    li.textContent = text;
    return li;
  }

  if (typeof document !== 'undefined') {
    document.addEventListener('click', function (e) {
      var button = e.target.closest && e.target.closest('[data-doc-check-run]');
      if (button) { e.preventDefault(); check(button); }
    });
  }

  var api = { analyseDocuments: analyseDocuments, appears: appears, person: person };
  root.RelationshipDocsScan = api;
})(typeof window !== 'undefined' ? window : globalThis);
