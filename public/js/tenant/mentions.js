/*
 * MARKER-NOTE-MENTIONS: type "@" in any note box marked [data-mentions] and
 * pick a teammate. The note stays plain text ("@Sam Jones"); the server finds
 * the names when it's saved and alerts each person in their bell
 * (App\Support\NoteMentions). Works on boxes added later (the detail modal
 * builds its own), so it listens on the document.
 *
 * IntakeMentions.html(text) escapes a note and marks the mentions, for notes
 * drawn in the browser.
 */
(function () {
  'use strict';
  var people = (window.IntakeMentionPeople || []).filter(function (p) { return p && p.name; });
  var menu = null, field = null, start = -1, items = [], pick = 0;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // same rule as the server: full name, or a first name nobody else has
  function handles() {
    var firsts = {};
    people.forEach(function (p) { var f = p.name.trim().split(/\s+/)[0].toLowerCase(); firsts[f] = (firsts[f] || 0) + 1; });
    var out = [];
    people.forEach(function (p) {
      var n = p.name.trim().replace(/\s+/g, ' ');
      out.push(n);
      var f = n.split(' ')[0];
      if (f !== n && firsts[f.toLowerCase()] === 1) out.push(f);
    });
    return out.sort(function (a, b) { return b.length - a.length; });
  }

  window.IntakeMentions = {
    html: function (text) {
      var h = esc(text);
      if (!people.length || h.indexOf('@') < 0) return h;
      var alts = handles().map(function (n) { return esc(n).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); });
      var re = new RegExp('(^|[^\\p{L}\\p{N}_])@(' + alts.join('|') + ')(?![\\p{L}\\p{N}_])', 'giu');
      return h.replace(re, function (m, pre, name) { return pre + '<span class="ia-mention">@' + name + '</span>'; });
    }
  };

  if (!people.length) return;

  function close() {
    if (menu) menu.remove();
    menu = null; field = null; start = -1; items = [];
  }

  function query(el) {
    var pos = el.selectionStart, v = el.value;
    if (pos == null) return null;
    var at = v.lastIndexOf('@', pos - 1);
    if (at < 0) return null;
    if (at > 0 && /[\p{L}\p{N}_]/u.test(v.charAt(at - 1))) return null; // an email address, not a mention
    var q = v.slice(at + 1, pos);
    if (q.length > 30 || /\n/.test(q) || /\s\s/.test(q)) return null;
    return { at: at, q: q };
  }

  function show(el) {
    var hit = query(el);
    if (!hit) return close();
    var q = hit.q.toLowerCase();
    var list = people.filter(function (p) {
      var n = p.name.toLowerCase();
      return !q || n.indexOf(q) === 0 || n.split(/\s+/).some(function (w) { return w.indexOf(q) === 0; });
    }).slice(0, 6);
    if (!list.length) return close();

    field = el; start = hit.at; items = list; pick = Math.min(pick, list.length - 1);
    if (!menu) {
      menu = document.createElement('div');
      menu.className = 'ia-mention-menu';
      menu.setAttribute('role', 'listbox');
      document.body.appendChild(menu);
      menu.addEventListener('mousedown', function (e) {
        var row = e.target.closest('[data-i]');
        if (!row) return;
        e.preventDefault();
        choose(parseInt(row.dataset.i, 10));
      });
    }
    menu.innerHTML = list.map(function (p, i) {
      return '<div class="ia-mention-opt' + (i === pick ? ' on' : '') + '" role="option" data-i="' + i + '">@' + esc(p.name) + '</div>';
    }).join('');
    var r = el.getBoundingClientRect();
    var below = window.innerHeight - r.bottom > 220;
    menu.style.left = Math.max(8, Math.min(r.left, window.innerWidth - 248)) + 'px';
    menu.style.top = (below ? r.bottom + 4 : Math.max(8, r.top - 4 - menu.offsetHeight)) + 'px';
  }

  function choose(i) {
    var p = items[i];
    if (!p || !field) return close();
    var el = field, v = el.value, pos = el.selectionStart;
    var ins = '@' + p.name + ' ';
    el.value = v.slice(0, start) + ins + v.slice(pos);
    var caret = start + ins.length;
    close();
    el.setSelectionRange(caret, caret);
    el.focus();
    el.dispatchEvent(new Event('input', { bubbles: true })); // character counters
  }

  document.addEventListener('input', function (e) {
    var el = e.target;
    if (!el.matches || !el.matches('[data-mentions]')) return;
    if (el === field && menu && start >= 0 && el.value.charAt(start) !== '@') { close(); }
    pick = 0;
    show(el);
  });

  document.addEventListener('keydown', function (e) {
    if (!menu || e.target !== field) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      pick = (pick + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length;
      show(field);
    } else if (e.key === 'Enter' || e.key === 'Tab') {
      e.preventDefault();
      e.stopImmediatePropagation(); // don't submit the note mid-name
      choose(pick);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      close();
    }
  }, true);

  document.addEventListener('click', function (e) { if (menu && e.target !== field && !menu.contains(e.target)) close(); });
  document.addEventListener('focusout', function (e) { if (e.target === field) setTimeout(function () { if (document.activeElement !== field) close(); }, 120); });
  window.addEventListener('resize', close);
  window.addEventListener('scroll', function () { if (field) show(field); }, true);
})();
