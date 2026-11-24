{{-- The one route-window edit panel, used by Booking Mode › Pickup & delivery
     and by Schedule › Deliveries (week + day).
     Open it with a button carrying data-rw-open (edit) or data-rw-add (add),
     each with a JSON data-rw payload. $rwRoutes picks the route names:
     'route_windows' (settings, Booking Mode access) or 'deliveries.windows'
     (calendar, Schedule access). --}}
@php
  $rwR = $rwRoutes ?? 'route_windows';
  $rwCfg = [
    'store'  => route('tenant.' . $rwR . '.store'),
    'base'   => rtrim(route('tenant.' . $rwR . '.update', ['id' => '__ID__']), '/'),
    'close'  => route('tenant.' . $rwR . '.close_day'),
    'reopen' => route('tenant.' . $rwR . '.reopen', ['id' => '__ID__']),
  ];
@endphp
<style>
  .rw-bg{position:fixed;inset:0;z-index:300;background:rgba(0,0,0,.5);display:none}
  .rw-bg.on{display:block}
  .rw-panel{position:fixed;top:0;right:0;bottom:0;z-index:301;width:440px;max-width:100vw;background:var(--ia-surface);border-left:.5px solid var(--ia-border-strong,var(--ia-border));display:none;flex-direction:column;box-shadow:-20px 0 48px rgba(0,0,0,.35)}
  .rw-panel.on{display:flex}
  .rw-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:20px 24px 16px;border-bottom:.5px solid var(--ia-border)}
  .rw-title{font-size:18px;font-weight:700}
  .rw-sub{font-size:12px;color:var(--ia-text-muted);margin-top:2px}
  .rw-x{border:0;background:none;color:var(--ia-text-muted);font-size:16px;width:32px;height:32px;border-radius:8px;cursor:pointer}
  .rw-x:hover{background:color-mix(in srgb,var(--ia-text) 6%,transparent);color:var(--ia-text)}
  .rw-body{flex:1;overflow:auto;padding:18px 24px;display:flex;flex-direction:column;gap:16px}
  .rw-split{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .rw-label{display:block;font-size:10.5px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--ia-text-muted);margin-bottom:6px}
  .rw-opt{text-transform:none;letter-spacing:0;font-weight:400;opacity:.7}
  .rw-in{width:100%;box-sizing:border-box;padding:9px 11px;background:var(--ia-surface-2);border:.5px solid var(--ia-border);border-radius:8px;color:var(--ia-text);font:inherit;font-size:13px}
  .rw-in:focus{outline:none;border-color:var(--ia-accent)}
  .rw-step{display:grid;grid-template-columns:36px 1fr 36px;gap:6px}
  .rw-step button{border:.5px solid var(--ia-border);border-radius:8px;background:var(--ia-surface-2);color:var(--ia-text);font-size:15px;cursor:pointer}
  .rw-step input{text-align:center}
  .rw-seg,.rw-days{display:flex;gap:4px;flex-wrap:wrap}
  .rw-seg button,.rw-days button{flex:1;padding:8px 10px;border-radius:8px;border:.5px solid var(--ia-border);background:none;color:var(--ia-text-muted);font:inherit;font-size:12.5px;cursor:pointer;white-space:nowrap}
  .rw-seg button.on,.rw-days button.on{background:var(--ia-accent-soft,color-mix(in srgb,var(--ia-accent) 18%,transparent));border-color:var(--ia-accent);color:var(--ia-text)}
  .rw-inline{display:flex;align-items:center;font-size:13px}
  .rw-inline .rw-tg{margin-left:auto}
  .rw-tg{position:relative;display:inline-block;width:40px;height:22px;flex:none}
  .rw-tg input{position:absolute;opacity:0;inset:0;margin:0;cursor:pointer}
  .rw-tg i{position:absolute;inset:0;border-radius:99px;background:color-mix(in srgb,var(--ia-text) 20%,transparent);transition:background .15s;pointer-events:none}
  .rw-tg i::after{content:'';position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:transform .15s}
  .rw-tg input:checked+i{background:var(--ia-accent)}
  .rw-tg input:checked+i::after{transform:translateX(18px)}
  .rw-refuse{padding:12px 14px;border-radius:10px;font-size:12.5px;line-height:1.5;background:color-mix(in srgb,#f09595 12%,var(--ia-surface));border:.5px solid color-mix(in srgb,#f09595 45%,transparent)}
  .rw-refuse b{display:block;margin-bottom:6px}
  .rw-refuse div{display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-top:.5px solid color-mix(in srgb,#f09595 25%,transparent)}
  .rw-refuse a{color:var(--ia-accent);text-decoration:none;white-space:nowrap}
  .rw-foot{display:flex;gap:8px;align-items:center;padding:14px 24px calc(14px + env(safe-area-inset-bottom,0px));border-top:.5px solid var(--ia-border)}
  .rw-link{border:0;background:none;padding:0;font:inherit;font-size:12.5px;color:var(--ia-accent);cursor:pointer}
  .rw-link.danger{color:#f09595}
  .rw-busy{opacity:.55;pointer-events:none}
  .rw-scrim{position:fixed;inset:0;z-index:320;background:rgba(0,0,0,.6);display:grid;place-items:center}
  .rw-scrim[hidden]{display:none}
  .rw-dlg{width:min(440px,calc(100% - 32px));background:var(--ia-surface);border:.5px solid var(--ia-border-strong,var(--ia-border));border-radius:14px;padding:20px 22px}
  .rw-dlg h4{margin:0 0 10px;font-size:16px;font-weight:600}
  .rw-dlg-b div{display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-top:.5px solid var(--ia-border);font-size:13px}
  .rw-dlg-b a{color:var(--ia-accent);text-decoration:none}
  .rw-dlg-f{display:flex;justify-content:flex-end;margin-top:16px}
  @media(max-width:640px){.rw-seg button{flex:1 1 100%}.rw-head{padding:16px}.rw-body{padding:16px}.rw-foot{padding:14px 16px calc(14px + env(safe-area-inset-bottom,0px))}}
</style>

<div class="rw-bg" id="rwBg"></div>
<aside class="rw-panel" id="rwPanel" role="dialog" aria-modal="true" aria-labelledby="rwTitle"
       data-cfg="{{ json_encode($rwCfg) }}">
  <div class="rw-head">
    <div><div class="rw-title" id="rwTitle">Window</div><div class="rw-sub" id="rwSub"></div></div>
    <button type="button" class="rw-x" data-rw-x aria-label="Close">✕</button>
  </div>
  <div class="rw-body">
    <div class="rw-split">
      <div><label class="rw-label" for="rwS">From</label><input class="rw-in" id="rwS" type="time" step="900"></div>
      <div><label class="rw-label" for="rwE">To</label><input class="rw-in" id="rwE" type="time" step="900"></div>
    </div>
    <div class="rw-split">
      <div><label class="rw-label" for="rwCap">Stops</label>
        <div class="rw-step"><button type="button" data-rw-step="-1" aria-label="One fewer">−</button><input class="rw-in" id="rwCap" type="number" min="1" max="50"><button type="button" data-rw-step="1" aria-label="One more">+</button></div></div>
      <div><label class="rw-label" for="rwName">Name <span class="rw-opt">optional</span></label><input class="rw-in" id="rwName" type="text" maxlength="40"></div>
    </div>
    <div id="rwScopeRow"><span class="rw-label">Applies to</span>
      <div class="rw-seg" id="rwScope"><button type="button" data-scope="date"></button><button type="button" data-scope="weekly">Every week</button></div></div>
    <div id="rwDaysRow" hidden><span class="rw-label">Runs on</span>
      <div class="rw-days" id="rwDays">@foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $i => $d)<button type="button" data-d="{{ $i + 1 }}">{{ $d }}</button>@endforeach</div></div>
    <div class="rw-inline"><span>Customers can book it</span>
      <button type="button" class="ia-tip" aria-label="What this does">i</button><span class="ia-tip-text" hidden>Off keeps the window for stops your team adds. Customers aren't offered it when they book or when you text delivery windows.</span>
      <label class="rw-tg"><input type="checkbox" id="rwBook" checked><i></i></label></div>
    <div class="rw-refuse" id="rwRefuse" hidden></div>
  </div>
  <div class="rw-foot">
    <button type="button" class="ia-btn ia-btn--primary" id="rwOk">Save</button>
    <button type="button" class="ia-btn" data-rw-x>Cancel</button>
    <span style="flex:1"></span>
    <button type="button" class="rw-link danger" id="rwRemove">Remove window</button>
  </div>
</aside>

<div class="rw-scrim" id="rwScrim" hidden><div class="rw-dlg" role="alertdialog" aria-modal="true" aria-labelledby="rwDlgT"><h4 id="rwDlgT"></h4><div class="rw-dlg-b" id="rwDlgB"></div><div class="rw-dlg-f"><button type="button" class="ia-btn ia-btn--sm" data-rw-dlg-x>Got it</button></div></div></div>

<script>
(function(){
  var P = document.getElementById('rwPanel'); if (!P || P.dataset.ready) return; P.dataset.ready = '1';
  var CFG = JSON.parse(P.dataset.cfg), $ = function(id){ return document.getElementById(id); };
  var DOW = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
  var S = $('rwS'), E = $('rwE'), CAP = $('rwCap'), NAME = $('rwName'), cur = null, scope = 'weekly';
  var csrf = (document.querySelector('meta[name=csrf-token]') || {}).content || '';

  function t12(t){ var p = t.split(':'), h = +p[0], m = +p[1], ap = h < 12 ? 'am' : 'pm', h12 = h % 12 || 12; return [m ? h12 + ':' + ('0' + m).slice(-2) : '' + h12, ap]; }
  function label(s, e){ if (!s || !e) return ''; var a = t12(s), b = t12(e); return a[1] === b[1] ? a[0] + '–' + b[0] + ' ' + b[1] : a[0] + ' ' + a[1] + '–' + b[0] + ' ' + b[1]; }
  function nice(d){ var x = new Date(d + 'T12:00:00'); return x.toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }); }
  function auto(){ NAME.placeholder = label(S.value, E.value) || 'Built from the times'; }
  S.addEventListener('input', auto); E.addEventListener('input', auto);

  function paintScope(){
    [].forEach.call($('rwScope').children, function(b){ b.classList.toggle('on', b.dataset.scope === scope); });
    $('rwDaysRow').hidden = scope !== 'weekly';
  }
  function setDays(list){ [].forEach.call($('rwDays').children, function(b){ b.classList.toggle('on', list.indexOf(+b.dataset.d) > -1); }); }
  function hideRefuse(){ $('rwRefuse').hidden = true; $('rwRefuse').innerHTML = ''; }
  function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function refuse(title, stops){
    var h = '<b>' + esc(title) + '</b>';
    (stops || []).forEach(function(s){ h += '<div><span>' + esc(s.name) + ' · ' + esc(s.type) + ' · ' + esc(s.when) + '</span><a href="' + esc(s.url) + '">Move</a></div>'; });
    $('rwRefuse').innerHTML = h; $('rwRefuse').hidden = false;
  }

  // d: {id?, s, e, cap, name, oneDate, date, dow, days, book, from: 'calendar'|'settings'}
  function open(d, add){
    cur = { d: d, add: add };
    S.value = add ? '' : d.s; E.value = add ? '' : d.e; CAP.value = add ? 3 : d.cap; NAME.value = add ? '' : (d.name || ''); auto();
    $('rwBook').checked = add ? true : !!d.book;
    var fromCal = d.from === 'calendar';
    $('rwTitle').textContent = add ? 'Add a window' : (d.name || label(d.s, d.e));
    $('rwSub').textContent = d.oneDate ? nice(d.date) + ' only'
      : (fromCal ? nice(d.date) : (add ? 'Every ' + DOW[d.dow - 1] : 'Every week'));
    // calendar: one date or every week; settings: weekly only; a one-date window stays one date
    scope = fromCal ? 'date' : 'weekly';
    $('rwScopeRow').hidden = !fromCal || d.oneDate;
    if (fromCal) $('rwScope').children[0].textContent = 'Just ' + nice(d.date);
    setDays(add || !d.days || !d.days.length ? [d.dow] : d.days); // adding: only the day you came from
    paintScope(); hideRefuse();
    $('rwRemove').hidden = add; $('rwOk').textContent = add ? 'Add window' : 'Save';
    $('rwBg').classList.add('on'); P.classList.add('on'); setTimeout(function(){ S.focus(); }, 30);
  }
  function close(){ $('rwBg').classList.remove('on'); P.classList.remove('on'); cur = null; }

  function send(method, url, body){
    P.classList.add('rw-busy');
    return fetch(url, { method: method, headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(body || {}) })
      .then(function(r){ return r.json().catch(function(){ return {}; }).then(function(j){ return { ok: r.ok, status: r.status, j: j }; }); })
      .then(function(res){
        if (res.ok) { location.reload(); return res; }
        P.classList.remove('rw-busy');
        if (res.j && res.j.refused) refuse(res.j.refused, res.j.stops);
        else if (res.j && res.j.errors) refuse(Object.values(res.j.errors)[0][0], []);
        else refuse(res.status === 419 ? 'Your session timed out. Reload the page and try again.' : 'That didn’t save. Nothing was changed.', []);
        return res;
      }, function(){ P.classList.remove('rw-busy'); refuse('Couldn’t reach the server. Nothing was changed.', []); });
  }

  $('rwOk').addEventListener('click', function(){
    if (!cur) return; hideRefuse();
    if (!S.value || !E.value) return refuse('Set a start and an end time.', []);
    if (S.value >= E.value) return refuse('The end time must be after the start time.', []);
    var days = [].filter.call($('rwDays').children, function(b){ return b.classList.contains('on'); }).map(function(b){ return +b.dataset.d; });
    if (scope === 'weekly' && !cur.d.oneDate && !days.length) return refuse('Pick at least one day.', []);
    var body = { starts_at: S.value, ends_at: E.value, max_stops: +CAP.value || 1, name: NAME.value.trim(), scope: cur.d.oneDate ? 'date' : scope,
      date: cur.d.date || null, days: days, customers_can_book: $('rwBook').checked ? 1 : 0 };
    if (cur.add) send('POST', CFG.store, body); else send('PATCH', CFG.base.replace('__ID__', cur.d.id), body);
  });

  $('rwRemove').addEventListener('click', function(){
    if (!cur || cur.add) return;
    var d = cur.d, oneDay = d.from === 'calendar' && !d.oneDate && scope === 'date';
    var msg = oneDay ? 'It comes off ' + nice(d.date) + ' only. Every other week stays as it is.'
      : (d.oneDate ? 'It comes off ' + nice(d.date) + '.' : 'It comes off every week it runs.');
    var go = function(){ send('DELETE', CFG.base.replace('__ID__', d.id), oneDay ? { scope: 'date', date: d.date } : {}); };
    if (window.IntakeConfirm && IntakeConfirm.show) IntakeConfirm.show({ title: 'Remove this window?', message: msg, confirmText: 'Remove' }).then(function(ok){ if (ok) go(); });
    else go();
  });

  document.addEventListener('click', function(e){
    var t = e.target;
    if (t.closest('[data-rw-dlg-x]') || t === $('rwScrim')) { $('rwScrim').hidden = true; return; }
    if (t.closest('[data-rw-x]') || t === $('rwBg')) { close(); return; }
    var st = t.closest('[data-rw-step]'); if (st) { CAP.value = Math.max(1, Math.min(50, (+CAP.value || 1) + (+st.dataset.rwStep))); return; }
    var sc = t.closest('#rwScope button'); if (sc) { scope = sc.dataset.scope; paintScope(); hideRefuse(); return; }
    var dy = t.closest('#rwDays button'); if (dy) { dy.classList.toggle('on'); return; }
    var o = t.closest('[data-rw-open],[data-rw-add]');
    if (o && !t.closest('a[href]:not([href="#"])') && !t.closest('[data-rw-stop]')) {
      e.preventDefault(); open(JSON.parse(o.dataset.rw), o.hasAttribute('data-rw-add'));
    }
  });
  document.addEventListener('keydown', function(e){
    if (e.key === 'Escape' && P.classList.contains('on')) close();
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('[data-rw-open][tabindex]')) { e.preventDefault(); e.target.click(); }
  });

  // close a day / reopen, from the calendar
  window.rwCloseDay = function(date, label){
    var go = function(){
      fetch(CFG.close, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ date: date }) })
        .then(function(r){ return r.json().catch(function(){ return {}; }).then(function(j){ return { ok: r.ok, j: j }; }); })
        .then(function(res){
          if (res.ok) return location.reload();
          var j = res.j || {};
          $('rwDlgT').textContent = j.refused || 'That day couldn’t be closed. Nothing was changed.';
          $('rwDlgB').innerHTML = (j.stops || []).map(function(s){ return '<div><span>' + esc(s.name) + ' · ' + esc(s.type) + ' · ' + esc(s.when) + '</span><a href="' + esc(s.url) + '">Move</a></div>'; }).join('');
          $('rwScrim').hidden = false;
        });
    };
    if (window.IntakeConfirm && IntakeConfirm.show) IntakeConfirm.show({ title: 'Close ' + label + '?', message: 'No windows run that day, so customers can’t book it. Every other week stays as it is.', confirmText: 'Close the day' }).then(function(ok){ if (ok) go(); });
    else go();
  };
  window.rwReopen = function(id){
    fetch(CFG.reopen.replace('__ID__', id), { method: 'DELETE', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf } })
      .then(function(r){ if (r.ok) location.reload(); else if (window.IntakeConfirm && IntakeConfirm.alert) IntakeConfirm.alert({ title: 'That didn’t reopen.', message: 'Nothing was changed.' }); });
  };
})();
</script>
