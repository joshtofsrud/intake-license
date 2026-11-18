{{-- MARKER-SYNC-PROGRESS: the live line for a distributor refresh.
     $code: the box's distributor, or null for every distributor (Catalog attention)
     $state: DistributorSyncProgress::state() at page load, so a run already
     going is picked up on a revisit without waiting for the first poll. --}}
<div class="dc-prog" data-sync-prog
     data-url="{{ route('tenant.distributors.connection.progress', array_filter(['code' => $code ?? null])) }}"
     data-state="{{ json_encode($state ?? ['state' => 'none']) }}" hidden>
  <div class="dc-prog-head">
    <span class="dc-prog-dot"></span>
    <b class="dc-prog-title"></b>
    <span class="dc-prog-el"></span>
    <button type="button" class="dc-prog-x" aria-label="Hide" hidden>&times;</button>
  </div>
  <div class="dc-prog-bar"><i></i></div>
  <div class="dc-prog-text"></div>
  <ul class="dc-prog-err" hidden></ul>
  <div class="dc-prog-act" hidden>
    <button type="button" class="dc-btn dc-prog-reload" style="padding:6px 12px;font-size:12.5px">Show the updated numbers</button>
  </div>
</div>

@once
@push('styles')
<style>
.dc-prog{margin-top:12px;padding:11px 13px;border:.5px solid var(--ia-border);border-radius:var(--ia-r-md);background:var(--ia-surface-2);font-size:12.5px}
.dc-prog-head{display:flex;align-items:center;gap:8px}
.dc-prog-title{font-weight:600;flex:1;min-width:0}
.dc-prog-el{font-family:var(--ia-mono);color:var(--ia-text-dim);font-size:12px}
.dc-prog-x{background:none;border:0;color:var(--ia-text-dim);font-size:16px;line-height:1;cursor:pointer;padding:0 2px}
.dc-prog-dot{width:8px;height:8px;border-radius:50%;background:var(--ia-accent);flex:none}
.dc-prog[data-s="queued"] .dc-prog-dot,.dc-prog[data-s="running"] .dc-prog-dot{animation:dcPulse 1.2s ease-in-out infinite}
.dc-prog[data-s="done"] .dc-prog-dot{background:#7fb33a}
.dc-prog[data-s="failed"] .dc-prog-dot{background:#E24B4A}
.dc-prog[data-s="stalled"] .dc-prog-dot{background:var(--ia-warn,#D9A441)}
@keyframes dcPulse{50%{opacity:.35}}
.dc-prog-bar{height:4px;border-radius:99px;background:rgba(127,127,127,.2);overflow:hidden;margin:8px 0 7px}
.dc-prog-bar i{display:block;height:100%;width:0;background:var(--ia-accent);transition:width .6s ease}
.dc-prog[data-s="done"] .dc-prog-bar i{background:#7fb33a}
.dc-prog[data-s="failed"] .dc-prog-bar i{background:#E24B4A}
.dc-prog-text{color:var(--ia-text-muted);line-height:1.5}
.dc-prog-err{margin:7px 0 0;padding-left:16px;color:#f0a3a3;font-size:12px;line-height:1.5}
.dc-prog-act{margin-top:9px}
</style>
@endpush
@push('scripts')
<script>
(function () {
  function fmt(s) { s = Math.max(0, s | 0); var m = Math.floor(s / 60); return m + ':' + String(s % 60).padStart(2, '0'); }
  function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }

  function Prog(el) {
    var q = function (c) { return el.querySelector(c); };
    var timer = null, tick = null, base = 0, baseAt = 0, live = false, hidden = false, lastRun = null;
    var startBtns = [];

    function render(s) {
      if (!s || s.state === 'none' || (hidden && s.run === lastRun)) { el.hidden = true; return; }
      hidden = false; lastRun = s.run;
      live = s.state === 'queued' || s.state === 'running';
      el.hidden = false;
      el.setAttribute('data-s', s.state);
      q('.dc-prog-title').textContent = s.state === 'done' ? 'Done' + (s.finished_ago ? ' · ' + s.finished_ago : '')
        : s.state === 'failed' ? 'Stopped' : s.state === 'stalled' ? 'Not moving' : (s.title || 'Working');
      q('.dc-prog-bar i').style.width = (s.pct || 0) + '%';
      q('.dc-prog-text').textContent = s.state === 'done' ? (s.note || s.text || '') : (s.text || '');
      base = s.elapsed || 0; baseAt = Date.now();
      q('.dc-prog-el').textContent = fmt(base);
      var errs = q('.dc-prog-err');
      if (s.errors && s.errors.length) {
        errs.innerHTML = s.errors.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('')
          + (s.error_count > s.errors.length ? '<li>and ' + (s.error_count - s.errors.length) + ' more</li>' : '');
        errs.hidden = false;
      } else { errs.hidden = true; }
      q('.dc-prog-x').hidden = live;
      q('.dc-prog-act').hidden = s.state !== 'done';
      startBtns.forEach(function (b) { b.disabled = live; });
      clearInterval(tick);
      if (live || s.state === 'stalled') {
        tick = setInterval(function () { q('.dc-prog-el').textContent = fmt(base + (Date.now() - baseAt) / 1000); }, 1000);
      }
      if (live || s.state === 'stalled') { schedule(); }
    }
    function schedule() { clearTimeout(timer); timer = setTimeout(poll, 2000); }
    function poll() {
      fetch(el.getAttribute('data-url'), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (s) { if (s) { render(s); } else { schedule(); } })
        .catch(schedule);
    }
    q('.dc-prog-x').addEventListener('click', function () { hidden = true; el.hidden = true; });
    q('.dc-prog-reload').addEventListener('click', function () { window.location.reload(); });

    // the buttons that start a run post here and follow it, instead of reloading the page
    this.bind = function (btn) {
      startBtns.push(btn);
      btn.addEventListener('click', function (e) {
        var form = btn.closest('form');
        if (!form) { return; }
        e.preventDefault();
        var fd = new FormData();
        form.querySelectorAll('input[type=hidden]').forEach(function (i) { fd.append(i.name, i.value); });
        startBtns.forEach(function (b) { b.disabled = true; });
        hidden = false;
        render({ state: 'queued', run: 'pending', title: btn.getAttribute('data-sync-title') || 'Starting', text: 'Sending…', pct: 0, elapsed: 0 });
        fetch(btn.getAttribute('formaction') || form.action, {
          method: 'POST', body: fd, credentials: 'same-origin',
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            if (res.ok && res.j.ok) { render(res.j.progress); }
            else { render({ state: 'failed', run: 'x', text: (res.j && res.j.message) || 'Could not start.', pct: 0, elapsed: 0 }); }
          })
          .catch(function () { render({ state: 'failed', run: 'x', text: 'Could not reach Intake. Check your connection and try again.', pct: 0, elapsed: 0 }); });
      });
    };
    try { render(JSON.parse(el.getAttribute('data-state'))); } catch (e) { el.hidden = true; }
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-sync-prog]').forEach(function (el) {
      var p = new Prog(el);
      // a start button belongs to the progress line in the same box (data-sync-scope)
      var scope = el.closest('[data-sync-scope]') || document;
      scope.querySelectorAll('[data-sync-start]').forEach(function (b) { p.bind(b); });
    });
  });
})();
</script>
@endpush
@endonce
