/* MARKER-SITE-HEALTH: the Site health page. Picks a row and shows its details, filters the
   list, runs a check with live progress (Stop / Resume), saves fixes, and talks to the
   Google panels. Every press shows what it did; the server's answer is the only truth:
   after anything changes, the list and the rail are fetched again and swapped in. */
(function () {
  'use strict';
  var root = document.getElementById('sh');
  if (!root) return;
  var URLS = JSON.parse(root.dataset.urls || '{}');
  var csrf = (document.querySelector('meta[name=csrf-token]') || {}).content || '';
  var toast = window.IntakeToast || { success: function () {}, error: function () {}, info: function () {} };
  var filter = 'all', picked = null, timer = null, busy = false, shutByHand = false;

  function $(s, c) { return (c || document).querySelector(s); }
  function $$(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }
  function phone() { return window.innerWidth <= 640; }

  function post(url, body) {
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(body || {})
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) {
          var first = j.errors ? j.errors[Object.keys(j.errors)[0]][0] : null;
          throw new Error(first || j.message || (r.status === 429 ? 'That was pressed too many times. Wait a minute.' : 'That did not work. Try again.'));
        }
        return j;
      });
    });
  }

  // ---- the list -------------------------------------------------------
  function rows() { return $$('#shList .sh-row'); }
  function applyFilter() {
    var shown = 0;
    rows().forEach(function (r) {
      var g = r.dataset.group, on = filter === 'all' ? g !== 'done' : g === filter;
      r.hidden = !on; if (on) shown++;
    });
    $$('#shSeg button').forEach(function (b) { b.classList.toggle('on', b.dataset.f === filter); });
    var none = $('#shNone'); if (none) none.hidden = shown > 0;
    var cur = picked && $('#shList .sh-row[data-id="' + picked + '"]');
    // nothing is opened for someone who has just closed the details themselves
    if (shutByHand && !cur) return;
    if (!cur || cur.hidden) { var first = rows().filter(function (r) { return !r.hidden; })[0]; pick(first ? first.dataset.id : null, true); }
  }
  // close the open details: the x on the card, the row pressed again, or Escape
  function shut() {
    var box = $('#shDetail'); if (!box) return;
    rows().forEach(function (r) { r.classList.remove('sel'); r.setAttribute('aria-expanded', 'false'); });
    box.hidden = true; box.innerHTML = ''; picked = null; shutByHand = true;
  }
  function pick(id, quiet) {
    var box = $('#shDetail'); if (!box) return;
    if (!quiet) shutByHand = false;
    picked = id;
    rows().forEach(function (r) { r.classList.toggle('sel', r.dataset.id === id); r.setAttribute('aria-expanded', r.dataset.id === id ? 'true' : 'false'); });
    var tpl = id && $('#shList template[data-for="' + id + '"]');
    if (!tpl) { box.hidden = true; box.innerHTML = ''; return; }
    box.innerHTML = ''; box.appendChild(tpl.content.cloneNode(true)); box.hidden = false;
    var row = $('#shList .sh-row[data-id="' + id + '"]');
    place();
    $$('form.sh-edit', box).forEach(live);
    // keep the opened details in view without jumping the page about
    if (!quiet && row) { var r = box.getBoundingClientRect(); if (r.bottom > window.innerHeight || r.top < 110) box.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
  }
  // the details open right under the picked row, on every screen: with a long list, details at the
  // foot of it would open out of sight and the press would look like it did nothing
  function place() {
    var box = $('#shDetail'), tpl = picked && $('#shList template[data-for="' + picked + '"]');
    if (!box || !tpl || box.hidden || box.previousSibling === tpl) return;
    tpl.parentNode.insertBefore(box, tpl.nextSibling);
  }

  // a title or description: the preview and the count follow the typing
  function live(form) {
    var input = form.elements.value, isTitle = form.dataset.field === 'title', suffix = form.dataset.suffix || '', max = +form.dataset.max || 60;
    var t = $('[data-sh-t]', form), d = $('[data-sh-d]', form), count = $('[data-sh-count]', form);
    function esc(s) { return s.replace(/[&<>]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]; }); }
    function draw() {
      var v = input.value.trim(), full = isTitle ? v + suffix : v, el = isTitle ? t : d;
      if (!v) el.textContent = isTitle ? suffix.replace(/^\s*[—-]\s*/, '') : 'Start typing to see it here.';
      else el.innerHTML = full.length > max ? esc(full.slice(0, max)) + '<span class="cut">' + esc(full.slice(max)) + '</span>' : esc(full);
      count.textContent = full.length + ' of about ' + max + (isTitle ? ', with your name added' : '');
      count.classList.toggle('over', full.length > max);
    }
    input.addEventListener('input', draw); draw();
  }

  function swap(part, id) {
    return fetch(URLS.page + '?part=' + part, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { if (!r.ok) throw new Error(); return r.text(); })
      .then(function (html) { var el = document.getElementById(id); if (el) el.innerHTML = html; });
  }
  function refreshMain() {
    var keep = picked, f = filter, had = {};
    rows().forEach(function (r) { had[r.dataset.id] = 1; });
    return swap('main', 'shMain').then(function () {
      if (Object.keys(had).length) rows().forEach(function (r) { if (!had[r.dataset.id]) r.classList.add('new'); });
      filter = $('#shSeg button[data-f="' + f + '"]') ? f : 'all';
      picked = $('#shList .sh-row[data-id="' + keep + '"]') ? keep : null;
      applyFilter(); if (picked) pick(picked, true);
    }).catch(function () { location.reload(); });
  }
  function refreshRail() {
    // with nothing beside the list (no basics yet, Google off) the list takes the full width
    return swap('rail', 'shRail').then(function () { $('#shGrid').classList.toggle('solo', !$('#shRail').children.length); }).catch(function () {});
  }

  // ---- a running check ------------------------------------------------
  function showRun(run) {
    var running = run.running;
    $('#shRun').hidden = !running; $('#shBar').hidden = !running; $('#shCheck').hidden = running;
    $('#shN').textContent = run.done.toLocaleString('en-US'); $('#shT').textContent = run.total.toLocaleString('en-US'); $('#shWhat').textContent = run.what;
    $('#shBar i').style.width = run.pct + '%';
    var res = $('#shResume'); res.hidden = running || !run.stopped;
    if (run.stopped) res.textContent = 'Resume from ' + run.done.toLocaleString('en-US') + ' of ' + run.total.toLocaleString('en-US');
    $('#shCheck').textContent = run.stopped ? 'Start over' : 'Check now';
    var err = $('#shErr'); if (err) err.hidden = running || !run.error;
  }
  var liveRows = -1;
  function showLive(s) {
    var box = $('#shLive'); if (!box || !s.live) return;
    $('#shLiveN').textContent = s.run.done.toLocaleString('en-US'); $('#shLiveT').textContent = s.run.total.toLocaleString('en-US');
    var total = 0;
    Object.keys(s.live.found).forEach(function (g) { total += s.live.found[g]; var el = $('[data-sh-tally="' + g + '"]', box); if (el) el.textContent = s.live.found[g].toLocaleString('en-US'); });
    ['photo', 'words'].forEach(function (k) { var el = $('[data-sh-tally="' + k + '"]', box); if (el) el.textContent = s.live[k].toLocaleString('en-US'); });
    $('#shLiveItems').hidden = !(s.live.photo || s.live.words);
    // new rows have been written since the list was drawn: bring them in
    if (liveRows !== -1 && total !== liveRows && s.run.running) refreshMain();
    liveRows = total;
  }
  function poll() {
    clearTimeout(timer);
    fetch(URLS.status, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (s) {
      var was = root.dataset.running === '1', gWas = root.dataset.googleRunning === '1';
      showRun(s.run); showLive(s);
      root.dataset.running = s.run.running ? '1' : '0'; root.dataset.googleRunning = s.google.running ? '1' : '0';
      if (was && !s.run.running) {
        refreshMain(); refreshRail();
        if (s.run.stopped) toast.info(s.run.error || 'Stopped. Resume picks up where it left off.'); else toast.success('Check finished.');
      }
      if (gWas && !s.google.running) { refreshRail(); refreshMain(); }
      if (s.run.running || s.google.running) timer = setTimeout(poll, 1500);
    }).catch(function () { timer = setTimeout(poll, 4000); });
  }
  function start(url, firstWord) {
    if (busy) return; busy = true;
    post(url).then(function () {
      root.dataset.running = '1';
      $('#shRun').hidden = false; $('#shBar').hidden = false; $('#shCheck').hidden = true; $('#shResume').hidden = true;
      if (firstWord) { $('#shN').textContent = '0'; $('#shBar i').style.width = '0%'; }
      liveRows = -1;
      // the page says what is happening now, not what was true when it loaded
      if ($('.sh-ghost') || $('#shLive')) refreshMain().then(poll); else poll();
    }).catch(function (e) { toast.error(e.message); }).then(function () { busy = false; });
  }

  // ---- presses --------------------------------------------------------
  document.addEventListener('click', function (e) {
    var t = e.target;
    var seg = t.closest('#shSeg button'); if (seg) { filter = seg.dataset.f; applyFilter(); return; }
    if (t.closest('[data-sh-shut]')) { var was = picked && $('#shList .sh-row[data-id="' + picked + '"]'); shut(); if (was) was.focus(); return; }
    // pressing the open row again closes it
    var row = t.closest('#shList .sh-row'); if (row) { if (row.dataset.id === picked) shut(); else pick(row.dataset.id); return; }
    if (t.closest('#shCheck') || t.closest('[data-sh-check]')) { start(URLS.check, true); return; }
    if (t.closest('#shResume')) { start(URLS.resume, false); return; }
    if (t.closest('#shStop')) { t.closest('#shStop').disabled = true; post(URLS.stop).then(poll).catch(function (er) { toast.error(er.message); }).then(function () { $('#shStop').disabled = false; }); return; }

    var more = t.closest('[data-sh-more]');
    if (more) {
      // the next 50 items behind this row, added under the ones already showing
      more.disabled = true;
      var shown = more.dataset.loaded ? (+more.dataset.shown || 0) : 0; // the first press starts from the top, in name order
      fetch(more.dataset.shMore + '?offset=' + shown, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
        .then(function (j) {
          var list = more.parentNode.querySelector('[data-sh-items]'), kind = list.dataset.shItems;
          if (!j.total) { more.textContent = 'Check again to list every item here.'; return; }
          // the first page replaces the few drawn with the row, so nothing shows twice
          if (!more.dataset.loaded) { list.innerHTML = ''; more.dataset.loaded = '1'; }
          j.items.forEach(function (it) {
            var a = document.createElement('a'); a.href = it.url;
            if (kind === 'photo') {
              a.className = 'sh-ph'; a.appendChild(document.createElement('span'));
              var em = document.createElement('em'); em.textContent = it.name; a.appendChild(em); list.appendChild(a);
            } else {
              a.textContent = it.name; var li = document.createElement('li'), sp = document.createElement('span');
              sp.textContent = it.words ? it.words + (it.words === 1 ? ' word' : ' words') : 'no description';
              li.appendChild(a); li.appendChild(sp); list.appendChild(li);
            }
          });
          var now = list.children.length; more.dataset.shown = j.next === null ? now : j.next;
          if (j.next === null) more.remove(); else { more.disabled = false; more.textContent = 'Show more · ' + now.toLocaleString('en-US') + ' of ' + j.total.toLocaleString('en-US'); }
        })
        .catch(function () { more.disabled = false; toast.error('Could not load more. Try again.'); });
      return;
    }
    var act = t.closest('[data-sh-post]');
    if (act) {
      act.disabled = true;
      post(act.dataset.shPost).then(function (j) { toast.success(j.message || 'Done.'); return refreshMain(); })
        .catch(function (er) { toast.error(er.message); act.disabled = false; });
      return;
    }
    if (t.closest('[data-sh-connect]')) { var sc = $('#shScrim'); if (sc) sc.hidden = false; return; }
    if (t.closest('[data-sh-close]') || t.id === 'shScrim') { $('#shScrim').hidden = true; return; }
    if (t.closest('#shRefresh')) {
      post(URLS.refresh).then(function () { root.dataset.googleRunning = '1'; toast.info('Reading from Google…'); return refreshRail(); }).then(poll).catch(function (er) { toast.error(er.message); });
      return;
    }
    var open = t.closest('[data-sh-reply-open]');
    if (open) { var f = open.closest('.sh-rev').querySelector('.sh-draft'); f.hidden = !f.hidden; if (!f.hidden) f.elements.text.focus(); return; }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && $('#shScrim') && !$('#shScrim').hidden) { $('#shScrim').hidden = true; return; }
    var row = e.target.closest && e.target.closest('#shList .sh-row');
    if (row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); if (row.dataset.id === picked) shut(); else pick(row.dataset.id); return; }
    if (e.key === 'Escape' && picked && !(e.target.matches && e.target.matches('input, textarea'))) shut();
  });

  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.matches('form.sh-edit')) {
      e.preventDefault();
      var btn = f.querySelector('[type=submit]'); btn.disabled = true;
      post(f.dataset.shSave, { value: f.elements.value.value }).then(function (j) { toast.success(j.message); return refreshMain(); })
        .catch(function (er) { toast.error(er.message); btn.disabled = false; });
    } else if (f.matches('form[data-sh-pick]')) {
      e.preventDefault();
      post(URLS.pick, { source: f.dataset.shPick, id: f.elements.id.value }).then(function (j) { toast.success(j.message); root.dataset.googleRunning = '1'; return refreshRail(); }).then(poll)
        .catch(function (er) { toast.error(er.message); });
    } else if (f.matches('form[data-sh-reply]')) {
      e.preventDefault();
      var text = f.elements.text.value.trim();
      if (!text) { toast.error('Write your reply first.'); return; }
      var ask = window.IntakeConfirm && window.IntakeConfirm.show
        ? window.IntakeConfirm.show({ title: 'Post this reply on Google?', message: 'Everyone who reads the review will see it, under your business name.', confirmText: 'Post reply', cancelText: 'Keep editing' })
        : Promise.resolve(false);
      ask.then(function (ok) {
        if (!ok) return;
        var b = f.querySelector('[type=submit]'); b.disabled = true;
        return post(URLS.reply, { review: f.dataset.shReply, text: text }).then(function (j) { toast.success(j.message); return refreshRail(); })
          .catch(function (er) { toast.error(er.message); b.disabled = false; });
      });
    }
  });

  // the header stays at the top on a wide screen. It only gains a hairline once the page is under it;
  // it never changes size, so scrolling can never fight it.
  function stuck() { var h = $('#shHead'); if (h) h.classList.toggle('stuck', window.scrollY > 4); }
  window.addEventListener('scroll', stuck, { passive: true });
  window.addEventListener('resize', stuck);
  stuck();
  applyFilter();
  if (location.hash === '#reviews' && $('#reviews')) $('#reviews').scrollIntoView();
  if (root.dataset.running === '1' || root.dataset.googleRunning === '1') poll();
})();
