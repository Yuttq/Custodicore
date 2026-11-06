{{--
  Script for PDL search boxes: a [data-pdl-search] box with data-url (JSON
  search endpoint), a hidden [data-pdl-id], a text input, a [role=listbox]
  and a [data-pdl-hint]. Works for any number of boxes on a page.
  Optional data-include-related="1": also list PDLs the visitor is already
  related to, labelled with that relationship's status.
--}}
@once
<script>
  // PDL search boxes ("Add PDL Relationship", Account Review's "Link to PDL"):
  // type a name, alias, PDL number or ID and pick from the list.
  document.querySelectorAll('[data-pdl-search]').forEach(function (box) {
    var input = box.querySelector('input[type="text"]');
    var hidden = box.querySelector('[data-pdl-id]');
    var list = box.querySelector('[role="listbox"]');
    var hint = box.querySelector('[data-pdl-hint]');
    var statusLabels = { active: 'Active', released: 'Released', transferred: 'Transferred', deceased: 'Deceased' };
    // Only sent with include_related (PDL request matching).
    var linkLabels = { pending: 'Pending link (will be reused)', rejected: 'Rejected link (will be reopened)', verified: 'Already approved link' };
    var items = [], active = -1, timer = null, lastQuery = null, request = 0;

    function requireChoice() {
      input.setCustomValidity(hidden.value ? '' : 'Choose a PDL from the search results.');
    }

    function close() {
      list.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      active = -1;
    }

    function highlight(i) {
      var rows = list.querySelectorAll('[role="option"]');
      rows.forEach(function (r, n) { r.classList.toggle('is-active', n === i); r.setAttribute('aria-selected', n === i ? 'true' : 'false'); });
      active = i;
      if (rows[i]) rows[i].scrollIntoView({ block: 'nearest' });
    }

    function choose(pdl) {
      hidden.value = pdl.id;
      input.value = pdl.name + ' (' + pdl.number + ')';
      hint.textContent = 'Selected: ' + pdl.name + ' · ' + pdl.number + ' · ' + (statusLabels[pdl.status] || pdl.status);
      requireChoice();
      close();
      hidden.dispatchEvent(new Event('change', { bubbles: true })); // let the page react to the pick
    }

    function render(results, q) {
      list.innerHTML = '';
      items = results;
      if (!results.length) {
        var none = document.createElement('li');
        none.className = 'pdl-search-empty';
        none.textContent = 'No PDL found for "' + q + '".' + (box.dataset.includeRelated ? '' : ' PDLs already linked to this visitor are not shown.');
        list.appendChild(none);
      }
      results.forEach(function (pdl, i) {
        var li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.id = 'pdl-opt-' + pdl.id;

        var avatar;
        if (pdl.photo) {
          avatar = document.createElement('img');
          avatar.src = pdl.photo;
          avatar.alt = '';
          avatar.className = 'thumb pdl-photo-img';
        } else {
          avatar = document.createElement('div');
          avatar.className = 'thumb';
          avatar.textContent = pdl.initials;
        }

        var text = document.createElement('div');
        var name = document.createElement('div');
        name.className = 'pdl-search-name';
        name.textContent = pdl.name + (pdl.alias ? ' "' + pdl.alias + '"' : '');
        var meta = document.createElement('div');
        meta.className = 'muted-cell';
        meta.textContent = pdl.number + ' · ID ' + pdl.id
          + (pdl.relationshipStatus ? ' · ' + (linkLabels[pdl.relationshipStatus] || pdl.relationshipStatus) : '');
        text.appendChild(name);
        text.appendChild(meta);

        var badge = document.createElement('span');
        badge.className = 'badge ' + pdl.status;
        badge.textContent = statusLabels[pdl.status] || pdl.status;

        li.appendChild(avatar);
        li.appendChild(text);
        li.appendChild(badge);
        li.addEventListener('mousedown', function (e) { e.preventDefault(); choose(pdl); });
        li.addEventListener('mousemove', function () { if (active !== i) highlight(i); });
        list.appendChild(li);
      });
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      if (results.length) highlight(0);
    }

    function search() {
      var q = input.value.trim();
      if (q === lastQuery && !list.hidden) return;
      lastQuery = q;
      if (!q) { close(); return; }
      var mine = ++request;
      var url = box.dataset.url + '?q=' + encodeURIComponent(q) + (box.dataset.includeRelated ? '&include_related=1' : '');
      fetch(url, { headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : { results: [] }; })
        .then(function (data) { if (mine === request) render(data.results || [], q); })
        .catch(function () { if (mine === request) render([], q); });
    }

    input.addEventListener('input', function () {
      // Typing again means the earlier choice no longer applies.
      if (hidden.value) { hidden.value = ''; hidden.dispatchEvent(new Event('change', { bubbles: true })); }
      hint.textContent = 'Type at least 1 character, then pick the PDL from the list.';
      requireChoice();
      clearTimeout(timer);
      timer = setTimeout(search, 200);
    });
    input.addEventListener('focus', function () { if (input.value.trim() && !hidden.value) search(); });
    input.addEventListener('blur', function () { setTimeout(close, 120); });
    input.addEventListener('keydown', function (e) {
      if (list.hidden) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); highlight(Math.min(active + 1, items.length - 1)); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(Math.max(active - 1, 0)); }
      else if (e.key === 'Enter') { if (items[active]) { e.preventDefault(); choose(items[active]); } }
      else if (e.key === 'Escape') { close(); }
    });

    requireChoice();
  });
</script>
@endonce
