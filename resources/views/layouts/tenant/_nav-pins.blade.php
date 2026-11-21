{{-- MARKER-NAV-PINS: pinning and reordering for the sidebar and the phone's More drawer.
     Both are drawn with every item as a [data-nav-row] inside either [data-nav-pins] (pinned, in order)
     or [data-nav-rest] (everything else, in menu order under its [data-nav-head] group heading).
     A pin or a drag here moves the rows in both at once, then saves the order to the person's account. --}}
<style>
  .ia-nav-pins[hidden], .ia-nav-more[hidden], .ia-drawer-pins[hidden], [data-nav-head][hidden] { display: none !important; }
  .ia-drawer-pin { flex: none; width: 34px; height: 34px; margin: -6px -8px -6px 0; display: grid; place-content: center; border-radius: 8px; color: var(--ia-text-3, #8a8a8a); opacity: .55; }
  .ia-drawer-pins .ia-drawer-pin { opacity: 1; color: var(--ia-accent, #e89a4f); }
  .ia-drawer-pins .ia-drawer-pin svg { fill: currentColor; }
  .ia-drawer-grip { display: none; flex: none; width: 26px; height: 34px; margin: -6px -6px -6px -10px; place-content: center; color: var(--ia-text-3, #8a8a8a); touch-action: none; cursor: grab; }
  .ia-drawer-pins .ia-drawer-grip { display: grid; }
  .ia-drawer-link.dragging { background: var(--ia-surface-2, rgba(255,255,255,.07)); opacity: .75; }
  .ia-drawer-sect-note { text-transform: none; letter-spacing: 0; font-weight: 400; opacity: .8; margin-left: 6px; }
  [data-nav-rest] .ia-drawer-sect:not([hidden]) ~ .ia-drawer-sect { position: relative; margin-top: 6px; }
  [data-nav-rest] .ia-drawer-sect:not([hidden]) ~ .ia-drawer-sect::before { content: ""; position: absolute; left: 20px; right: 20px; top: 0; height: 1px; background: var(--ia-border, rgba(255,255,255,.08)); }
  @media (max-width: 760px) { .ia-mobile-nav-icon > svg { width: 20px; height: 20px; } }
</style>
<script>
(function () {
  if (window.iaNavPins) return; window.iaNavPins = 1;
  var url = @json(route('tenant.nav.pins'));
  var token = (document.querySelector('meta[name=csrf-token]') || {}).content || '';
  function all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function boxes() {
    return all('[data-nav-pins]').map(function (p) {
      var rest = p.parentElement.querySelector('[data-nav-rest]');
      return rest ? { pins: p, rest: rest } : null;
    }).filter(Boolean);
  }
  function rowsIn(el) { return all('[data-nav-row]', el); }
  function order() { var b = boxes()[0]; return b ? rowsIn(b.pins).map(function (r) { return r.dataset.route; }) : []; }

  // put a box's rows where the list of pinned routes says they go
  function arrange(box, pinned) {
    var byRoute = {};
    rowsIn(box.pins).concat(rowsIn(box.rest)).forEach(function (r) { byRoute[r.dataset.route] = r; });
    var end = box.pins.querySelector('.ia-drawer-rule');
    pinned.forEach(function (route) { var r = byRoute[route]; if (r) { box.pins.insertBefore(r, end); delete byRoute[route]; } });
    Object.keys(byRoute).forEach(function (route) {
      var r = byRoute[route];
      if (r.parentElement === box.rest) { return; }
      // back to its own place: before the first row or later group heading that comes after it in the menu
      var idx = +r.dataset.idx, before = null, seenOwnHead = !r.dataset.group || !box.rest.querySelector('[data-nav-head="' + r.dataset.group + '"]');
      all(':scope > *', box.rest).some(function (el) {
        if (el.hasAttribute('data-nav-head')) {
          if (el.dataset.navHead === (r.dataset.group || 'workspace') || el.dataset.navHead === r.dataset.group) { seenOwnHead = true; return false; }
          if (seenOwnHead) { before = el; return true; }
          return false;
        }
        if (el.hasAttribute('data-nav-row') && +el.dataset.idx > idx) { before = el; return true; }
        return false;
      });
      box.rest.insertBefore(r, before);
    });
    // a group heading shows only while it has something under it
    all('[data-nav-head]', box.rest).forEach(function (h) {
      h.hidden = !rowsIn(box.rest).some(function (r) { return (r.dataset.group || 'workspace') === h.dataset.navHead || r.dataset.group === h.dataset.navHead; });
    });
    var has = pinned.length > 0;
    box.pins.hidden = !has;
    var more = document.getElementById('ia-nav-more');
    if (box.rest.id === 'ia-nav-fold' && more) {
      more.hidden = !has;
      var n = more.querySelector('[data-nav-rest-n]'); if (n) { n.textContent = rowsIn(box.rest).length; }
      box.rest.classList.toggle('folded', has && more.getAttribute('aria-expanded') !== 'true');
    }
  }
  function apply(pinned) { boxes().forEach(function (b) { arrange(b, pinned); }); }

  var saving = 0;
  function save(pinned, undo) {
    var mine = ++saving;
    fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token }, body: JSON.stringify({ pins: pinned }) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok && j.ok, j: j }; }); })
      .then(function (res) { if (!res.ok) { throw new Error((res.j && res.j.error) || ''); } })
      .catch(function (e) {
        if (mine !== saving) { return; }
        apply(undo);
        if (window.IntakeToast) { IntakeToast.error(e.message || 'Your pins couldn\'t be saved. Nothing was changed.'); }
      });
  }

  function toggle(row) {
    var was = order(), route = row.dataset.route, now = was.slice(), i = now.indexOf(route);
    if (i === -1) { now.push(route); } else { now.splice(i, 1); }
    // the first pin folds the rest away; keep it open for now so more can be pinned without hunting for them
    var more = document.getElementById('ia-nav-more');
    if (!was.length && more) { more.setAttribute('aria-expanded', 'true'); }
    apply(now); save(now, was);
  }
  document.addEventListener('click', function (e) {
    var pin = e.target.closest && e.target.closest('[data-nav-pin]');
    if (pin) { e.preventDefault(); e.stopPropagation(); toggle(pin.closest('[data-nav-row]')); return; }
    var more = e.target.closest && e.target.closest('#ia-nav-more');
    if (more) {
      var open = more.getAttribute('aria-expanded') !== 'true';
      more.setAttribute('aria-expanded', open ? 'true' : 'false');
      document.getElementById('ia-nav-fold').classList.toggle('folded', !open);
      try { localStorage.setItem('ia-nav-more', open ? '1' : '0'); } catch (err) {}
    }
  }, true);
  document.addEventListener('keydown', function (e) {
    var pin = e.target.closest && e.target.closest('[data-nav-pin]');
    if (pin && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); e.stopPropagation(); toggle(pin.closest('[data-nav-row]')); }
  }, true);

  // drag a pinned row to reorder: anywhere on the row with a mouse, by its handle on a touch screen
  var drag = null, eat = false;
  document.addEventListener('dragstart', function (e) { if (e.target.closest && e.target.closest('[data-nav-row]')) { e.preventDefault(); } });
  document.addEventListener('pointerdown', function (e) {
    var row = e.target.closest && e.target.closest('[data-nav-row]');
    if (!row || !row.parentElement.hasAttribute('data-nav-pins') || e.button || e.target.closest('[data-nav-pin]')) { return; }
    if (e.pointerType !== 'mouse' && !e.target.closest('.ia-nav-grip')) { return; }
    drag = { row: row, box: row.parentElement, y: e.clientY, on: false, was: order() };
  });
  document.addEventListener('pointermove', function (e) {
    if (!drag) { return; }
    if (!drag.on) {
      if (Math.abs(e.clientY - drag.y) < 5) { return; }
      drag.on = true; drag.row.classList.add('dragging');
    }
    e.preventDefault();
    var others = rowsIn(drag.box).filter(function (r) { return r !== drag.row; }), before = null;
    others.some(function (r) { var b = r.getBoundingClientRect(); if (e.clientY < b.top + b.height / 2) { before = r; return true; } return false; });
    var end = drag.box.querySelector('.ia-drawer-rule');
    drag.box.insertBefore(drag.row, before || end);
  }, { passive: false });
  function drop() {
    if (!drag) { return; }
    var d = drag; drag = null;
    if (!d.on) { return; }
    d.row.classList.remove('dragging'); eat = true; setTimeout(function () { eat = false; }, 50);
    var now = rowsIn(d.box).map(function (r) { return r.dataset.route; });
    if (now.join('|') === d.was.join('|')) { return; }
    apply(now); save(now, d.was);
  }
  document.addEventListener('pointerup', drop);
  document.addEventListener('pointercancel', drop);
  // letting go of a dragged row is not a click on its link
  document.addEventListener('click', function (e) { if (eat && e.target.closest && e.target.closest('[data-nav-row]')) { e.preventDefault(); e.stopPropagation(); } }, true);
})();
</script>
