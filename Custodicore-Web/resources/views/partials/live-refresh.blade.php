{{--
  Keeps a list page current without the officer pressing refresh. Include it
  inside a page's content section.

  Every 30 seconds, while the tab is visible, it re-fetches this same page
  (same filters and page number) and compares the <main> content with the
  first copy it fetched. Only when the list actually changed does it reload,
  and only at a safe moment: never while a field is being typed in or was
  changed, a popup is open, text is selected, a reject/details panel is
  open, or the officer clicked, typed or scrolled in the last 15 seconds.
  Otherwise a "New updates — Refresh" bar appears and the reload waits.
  The scroll position is kept across the reload.

  Parts that change on their own (e.g. "5 minutes ago") are marked
  data-live-ignore so they never count as a change.
--}}
<script>
  (function () {
    if (window.ccLiveRefresh) return;
    window.ccLiveRefresh = true;

    var INTERVAL_MS = 30000;
    var QUIET_MS = 15000;
    var SCROLL_KEY = 'cc-live-scroll:' + location.pathname + location.search;

    var baseline = null;   // <main> of the first fetch, normalized
    var stale = false;     // the list changed since this page was loaded
    var dirty = false;     // a field was changed: never reload over it
    var fetching = false;
    var lastActivity = Date.now();
    var banner = null;

    // Back where the officer was after our own reload.
    window.addEventListener('load', function () {
      try {
        var y = sessionStorage.getItem(SCROLL_KEY);
        if (y !== null) {
          sessionStorage.removeItem(SCROLL_KEY);
          window.scrollTo(0, Number(y) || 0);
        }
      } catch (e) { /* storage blocked */ }
    });

    ['pointerdown', 'keydown', 'wheel', 'touchstart'].forEach(function (type) {
      document.addEventListener(type, function () { lastActivity = Date.now(); }, { passive: true, capture: true });
    });
    ['input', 'change'].forEach(function (type) {
      document.addEventListener(type, function (e) {
        if (e.target && e.target.closest && e.target.closest('main')) dirty = true;
      }, true);
    });
    document.addEventListener('submit', function () { dirty = true; }, true);

    function normalize(html) {
      var doc = new DOMParser().parseFromString(html, 'text/html');
      var main = doc.querySelector('main');
      if (!main) return null;
      main.querySelectorAll('[data-live-ignore], script, #flashModal, input[name="_token"]').forEach(function (n) { n.remove(); });
      return main.innerHTML.replace(/\s+/g, ' ').trim();
    }

    function userBusy() {
      if (dirty) return true;
      if (Date.now() - lastActivity < QUIET_MS) return true;
      if (document.querySelector('dialog[open], #flashModal')) return true;
      if (document.querySelector('main details[open]:not([data-remember-open])')) return true;
      var active = document.activeElement;
      if (active && active !== document.body && active.matches('input, textarea, select, [contenteditable="true"]')) return true;
      if (window.getSelection && String(window.getSelection())) return true;
      return false;
    }

    function showBanner() {
      if (banner) return;
      banner = document.createElement('div');
      banner.setAttribute('role', 'status');
      banner.style.cssText = 'position:fixed;left:50%;bottom:20px;transform:translateX(-50%);z-index:60;'
        + 'display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:10px;'
        + 'background:#1f2937;color:#fff;font-size:14px;box-shadow:0 6px 20px rgba(0,0,0,.25);';
      banner.appendChild(document.createTextNode('This list has new updates.'));
      var button = document.createElement('button');
      button.type = 'button';
      button.textContent = 'Refresh';
      button.style.cssText = 'background:#fff;color:#1f2937;border:0;border-radius:6px;padding:4px 10px;font-weight:600;cursor:pointer;';
      button.addEventListener('click', reloadKeepingScroll);
      banner.appendChild(button);
      document.body.appendChild(banner);
    }

    function reloadKeepingScroll() {
      try { sessionStorage.setItem(SCROLL_KEY, String(window.scrollY)); } catch (e) { /* storage blocked */ }
      location.reload();
    }

    function reloadWhenSafe() {
      if (!stale || document.hidden) return;
      if (userBusy()) { showBanner(); return; }
      reloadKeepingScroll();
    }

    function tick() {
      if (document.hidden) return;
      if (stale) { reloadWhenSafe(); return; }
      if (fetching) return;
      fetching = true;
      fetch(location.href, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html' } })
        .then(function (response) {
          // Signed out or sent elsewhere: leave the page as it is.
          return response.ok && !response.redirected ? response.text() : null;
        })
        .then(function (html) {
          var snapshot = html === null ? null : normalize(html);
          if (snapshot === null) return;
          if (baseline === null) { baseline = snapshot; return; }
          if (snapshot !== baseline) { stale = true; reloadWhenSafe(); }
        })
        .catch(function () { /* offline: try again next time */ })
        .then(function () { fetching = false; });
    }

    tick(); // the copy to compare against
    setInterval(tick, INTERVAL_MS);
    document.addEventListener('visibilitychange', tick);
  })();
</script>
