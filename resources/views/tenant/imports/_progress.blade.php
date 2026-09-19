{{-- MARKER-IMPORT-QUEUE — the work, made visible.

     Included by the preview and show screens. Opens itself whenever the
     import is mid-flight, including after a page reload or on another device,
     because progress lives on the import row rather than in this page. --}}
<div class="imp-prog-bg" id="imp-prog" role="dialog" aria-modal="true" aria-labelledby="imp-prog-title">
  <div class="imp-prog">
    <div class="imp-prog-title" id="imp-prog-title">Working…</div>
    <div class="imp-prog-sub" id="imp-prog-sub">Starting up.</div>

    <div class="imp-prog-bar"><div class="imp-prog-fill" id="imp-prog-fill" style="width:0%"></div></div>
    <div class="imp-prog-nums">
      <span id="imp-prog-count">0 rows</span>
      <span id="imp-prog-elapsed" style="margin-left:auto"></span>
    </div>

    <div class="imp-prog-tallies" id="imp-prog-tallies"></div>

    <div class="imp-prog-warn" id="imp-prog-warn"></div>

    <div class="imp-prog-actions">
      <button type="button" class="ia-btn ia-btn--secondary" id="imp-prog-cancel">Stop this import</button>
      <button type="button" class="ia-btn ia-btn--secondary" id="imp-prog-hide" style="display:none">Close</button>
      <button type="button" class="ia-btn ia-btn--primary"   id="imp-prog-go"   style="display:none">Continue</button>
    </div>
  </div>
</div>

<style>
  .imp-prog-bg{position:fixed;inset:0;background:rgba(0,0,0,.72);display:none;align-items:center;justify-content:center;z-index:1100;padding:20px}
  .imp-prog-bg.open{display:flex}
  .imp-prog{background:var(--ia-surface);border:0.5px solid var(--ia-border);border-radius:var(--ia-r-lg);padding:22px 24px;width:100%;max-width:460px}
  .imp-prog-title{font-size:16px;font-weight:600}
  .imp-prog-sub{font-size:12.5px;color:var(--ia-text-dim);margin-top:3px}
  .imp-prog-bar{height:8px;border-radius:99px;background:var(--ia-surface-2);overflow:hidden;margin-top:16px}
  .imp-prog-fill{height:100%;background:var(--ia-accent);width:0;transition:width .4s ease}
  .imp-prog-fill.indeterminate{width:35% !important;animation:impslide 1.1s ease-in-out infinite alternate}
  @keyframes impslide{from{margin-left:0}to{margin-left:65%}}
  .imp-prog-nums{display:flex;font-size:12px;color:var(--ia-text-muted);margin-top:8px;font-variant-numeric:tabular-nums}
  .imp-prog-tallies{display:flex;flex-wrap:wrap;gap:6px;margin-top:14px}
  .imp-prog-tallies span{font-size:11.5px;border:0.5px solid var(--ia-border);border-radius:99px;padding:3px 9px;color:var(--ia-text-muted)}
  .imp-prog-warn{display:none;margin-top:14px;font-size:12.5px;line-height:1.55;border-radius:var(--ia-r-md);padding:9px 12px}
  .imp-prog-warn.stalled{display:block;background:rgba(240,196,106,.1);color:#f0c46a}
  .imp-prog-warn.failed{display:block;background:var(--ia-red-soft);color:var(--ia-red)}
  .imp-prog-actions{display:flex;gap:8px;margin-top:18px;justify-content:flex-end}
</style>

<script>
(function () {
  var el = {
    bg: document.getElementById('imp-prog'),
    title: document.getElementById('imp-prog-title'),
    sub: document.getElementById('imp-prog-sub'),
    fill: document.getElementById('imp-prog-fill'),
    count: document.getElementById('imp-prog-count'),
    elapsed: document.getElementById('imp-prog-elapsed'),
    tallies: document.getElementById('imp-prog-tallies'),
    warn: document.getElementById('imp-prog-warn'),
    cancel: document.getElementById('imp-prog-cancel'),
    hide: document.getElementById('imp-prog-hide'),
    go: document.getElementById('imp-prog-go')
  };

  var progressUrl = @json(route('tenant.imports.progress', $import->id));
  var cancelUrl   = @json(route('tenant.imports.cancel', $import->id));
  var csrf        = @json(csrf_token());
  var started     = Date.now();
  var timer       = null;
  var LABELS      = { create: 'created', update: 'updated', possible_duplicate: 'possible duplicates',
                      unchanged: 'already match', skipped: 'skipped', unmatched: 'no match', error: 'errors' };

  function fmt(n) { return (n || 0).toLocaleString(); }

  function elapsed() {
    var s = Math.round((Date.now() - started) / 1000);
    return s < 60 ? s + 's' : Math.floor(s / 60) + 'm ' + (s % 60) + 's';
  }

  function paint(d) {
    var pct = d.total > 0 ? Math.min(100, Math.round((d.done / d.total) * 100)) : 0;
    el.fill.classList.toggle('indeterminate', d.total === 0 && !d.finished);
    if (d.total > 0) { el.fill.style.width = pct + '%'; }

    el.count.textContent = d.total > 0
      ? fmt(d.done) + ' of ' + fmt(d.total) + ' rows · ' + pct + '%'
      : fmt(d.done) + ' rows';
    el.elapsed.textContent = elapsed();

    el.tallies.innerHTML = '';
    if (d.live) {
      Object.keys(d.live).forEach(function (k) {
        if (!d.live[k]) return;
        var s = document.createElement('span');
        s.textContent = fmt(d.live[k]) + ' ' + (LABELS[k] || k);
        el.tallies.appendChild(s);
      });
    }

    el.warn.className = 'imp-prog-warn';
    if (d.stage === 'failed') {
      el.title.textContent = 'It stopped';
      el.sub.textContent = 'Nothing further will be written.';
      el.warn.className = 'imp-prog-warn failed';
      el.warn.textContent = d.reason || 'No reason was recorded — the application log for today has the trace.';
    } else if (d.stage === 'cancelled') {
      el.title.textContent = 'Stopped';
      el.sub.textContent = 'Anything already written stays. Nothing further was.';
    } else if (d.finished) {
      el.title.textContent = 'Finished';
      el.sub.textContent = 'Every row has an outcome.';
      el.fill.style.width = '100%';
    } else if (d.stalled) {
      el.title.textContent = 'Still going, but nothing has moved';
      el.sub.textContent = 'No update for ' + d.seen_ago + ' seconds.';
      el.warn.className = 'imp-prog-warn stalled';
      el.warn.textContent = 'The worker may have stopped. Leave this open a moment; if nothing moves, stop the import and try again — nothing is lost, every row already written has a recorded outcome.';
    } else {
      el.title.textContent = d.stage === 'previewing' ? 'Working out what will happen' : 'Importing';
      el.sub.textContent = d.stage === 'previewing'
        ? 'Reading the file and matching every row. Nothing is being written.'
        : 'Writing rows. You can leave this page — it keeps going.';
    }

    var done = d.finished || d.stage === 'failed' || d.stage === 'cancelled';
    el.cancel.style.display = done ? 'none' : '';
    el.hide.style.display   = done ? '' : 'none';
    el.go.style.display     = (d.stage === 'finished') ? '' : 'none';
  }

  function poll() {
    fetch(progressUrl, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (['previewing', 'running'].indexOf(d.stage) !== -1 || d.stage === 'failed' || d.stage === 'cancelled') {
          el.bg.classList.add('open');
        }
        paint(d);
        if (d.finished) {
          clearInterval(timer);
          if (d.stage === 'finished') { setTimeout(function () { window.location.reload(); }, 900); }
        }
      })
      .catch(function () { /* a dropped poll is not a failure; the next one tells the truth */ });
  }

  el.cancel.addEventListener('click', function () {
    el.cancel.disabled = true;
    el.sub.textContent = 'Stopping after the current batch…';
    fetch(cancelUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } });
  });
  el.hide.addEventListener('click', function () { el.bg.classList.remove('open'); });
  el.go.addEventListener('click', function () { window.location.reload(); });

  window.impWatchImport = function () {
    el.bg.classList.add('open');
    started = Date.now();
    poll();
    clearInterval(timer);
    timer = setInterval(poll, 1500);
  };

  // Reattach on load whenever the import is mid-flight.
  @if(in_array($import->progress_stage, ['previewing', 'running'], true))
    window.impWatchImport();
  @else
    poll();
    timer = setInterval(poll, 2500);
  @endif
})();
</script>
