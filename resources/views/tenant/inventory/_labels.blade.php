{{--
  MARKER-LABELS: the Print labels panel. Same look as the Print & Send
  composer (tenant/print/_composer). Open it with
    openLabels({ shipment: id })   a receiving shipment ("received" counts)
    openLabels({ items: [ids] })   items picked on the inventory list
    openLabels({ items: [id] })    one item page
  Data: inventory.labels.data · preview + print: inventory.labels.sheet (POST
  into the frame, so a long list never makes a too-long URL) · the shop's
  choice is remembered via inventory.labels.prefs when it prints.
--}}
@once
<div id="lbx" class="lbx-overlay" hidden>
  <div class="pc-dialog lbx-dialog" role="dialog" aria-modal="true" aria-labelledby="lbx-title">
    <div class="pc-wrap">
      <div class="pc-head">
        <div><div class="pc-title" id="lbx-title">Print labels</div><div class="pc-ctx" id="lbx-ctx">Loading…</div></div>
        <button type="button" class="pc-x" data-lbx-close aria-label="Close">&times;</button>
      </div>
      <div class="pc-body lbx-body">
        <div class="pc-controls">
          <div class="pc-grp">
            <div class="pc-lbl">Barcode</div>
            <div class="pc-seg" id="lbx-code">
              <button type="button" data-v="upc">UPC</button>
              <button type="button" data-v="sku">SKU</button>
              <button type="button" data-v="mpn">MPN</button>
            </div>
            <div class="lbx-hint" id="lbx-code-hint"></div>
          </div>
          <div class="pc-grp">
            <div class="pc-lbl">How many</div>
            <div class="pc-seg pc-col" id="lbx-qty"></div>
            <div class="lbx-setn" id="lbx-setn" hidden>
              <button type="button" data-d="-1" aria-label="Fewer">−</button><input type="number" id="lbx-n" min="1" max="999" value="1" aria-label="Labels of each item"><button type="button" data-d="1" aria-label="More">+</button>
              <span>of each</span>
            </div>
          </div>
          <div class="pc-grp">
            <div class="pc-lbl">Items <span id="lbx-total" class="lbx-total"></span></div>
            <div id="lbx-items"></div>
          </div>
          <div class="pc-grp">
            <div class="pc-lbl">On the label</div>
            <label class="pc-tg" data-inc="name"><span>Item name</span><i class="pc-sw"></i></label>
            <label class="pc-tg" data-inc="price"><span>Price</span><i class="pc-sw"></i></label>
            <label class="pc-tg" data-inc="variant"><span>Size / color</span><i class="pc-sw"></i></label>
            <label class="pc-tg" data-inc="bin"><span>Bin location</span><i class="pc-sw"></i></label>
            <label class="pc-tg" data-inc="shop"><span>Shop name</span><i class="pc-sw"></i></label>
          </div>
          <div class="pc-grp">
            <div class="pc-lbl">Label stock <span class="lbx-saved">remembered for next time</span></div>
            <div class="pc-seg pc-col" id="lbx-stock"></div>
            <div id="lbx-start" hidden>
              <div class="lbx-hint" style="margin:10px 0 6px">Start at label — skip ones already used on a part sheet</div>
              <div class="lbx-sheetpick" id="lbx-sheetpick"></div>
            </div>
          </div>
          <div class="pc-grp">
            <button type="button" class="pc-go" id="lbx-go">Print</button>
            <div class="lbx-legend">Printing doesn't change stock, the items or the shipment. An item with nothing for the chosen barcode shows what it will use instead.</div>
          </div>
        </div>
        <div class="pc-preview">
          <div class="pc-pv-head" id="lbx-pv-head">Preview</div>
          <iframe id="lbx-frame" name="lbx-frame" title="Label preview"></iframe>
        </div>
      </div>
    </div>
  </div>
  <form id="lbx-form" method="POST" action="{{ route('tenant.inventory.labels.sheet') }}" target="lbx-frame" hidden>@csrf</form>
</div>

<style>

  #lbx .pc-dialog{border:0;border-radius:16px;padding:0;background:#141414;color:#f0f0f0;
    width:min(940px,94vw);max-width:94vw;box-shadow:0 30px 80px rgba(0,0,0,.6);
    margin:auto;max-height:92vh;overflow:hidden}
  #lbx .pc-wrap{font-family:Inter,system-ui,sans-serif;display:flex;flex-direction:column;max-height:92vh}
  #lbx .pc-head{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid rgba(255,255,255,.08)}
  #lbx .pc-title{font-size:16px;font-weight:600}
  #lbx .pc-ctx{font-size:12px;color:rgba(255,255,255,.5);margin-top:2px}
  #lbx .pc-x{background:none;border:0;color:rgba(255,255,255,.5);font-size:24px;cursor:pointer;line-height:1}
  #lbx .pc-body{display:grid;grid-template-columns:300px 1fr;gap:0;flex:1 1 auto;min-height:0}
  
  #lbx .pc-controls{padding:6px 18px 18px;overflow:auto}
  #lbx .pc-grp{padding:14px 0;border-bottom:1px solid rgba(255,255,255,.07)}
  #lbx .pc-grp:last-child{border-bottom:0}
  #lbx .pc-lbl{font-size:10px;letter-spacing:.13em;text-transform:uppercase;color:rgba(255,255,255,.32);font-weight:600;margin-bottom:10px;display:flex;justify-content:space-between}
  #lbx .pc-lbl a{color:#BEF264;text-decoration:none;text-transform:none;letter-spacing:0;font-size:11px}
  #lbx .pc-seg{display:flex;gap:6px;flex-wrap:wrap}
  #lbx .pc-seg.pc-col{flex-direction:column}
  #lbx .pc-seg button{flex:1;background:#0a0a0a;border:1px solid rgba(255,255,255,.08);color:rgba(255,255,255,.5);
    border-radius:9px;padding:9px 10px;font-size:12.5px;font-weight:500;cursor:pointer;white-space:nowrap}
  #lbx .pc-seg button.on{background:#BEF264;border-color:#BEF264;color:#0c0c0c;font-weight:600}
  #lbx .pc-seg button[disabled]{opacity:.3;cursor:not-allowed}
  #lbx .pc-asset{display:flex;align-items:center;gap:9px;background:#0a0a0a;border:1px solid rgba(255,255,255,.08);
    border-radius:9px;padding:9px 11px;margin-bottom:7px;cursor:pointer;font-size:13px}
  #lbx .pc-asset.on{border-color:rgba(190,242,100,.5);background:rgba(190,242,100,.05)}
  #lbx .pc-asset i{width:16px;height:16px;border-radius:4px;border:1.5px solid rgba(255,255,255,.3);flex:0 0 auto}
  #lbx .pc-asset.on i{background:#BEF264;border-color:#BEF264}
  #lbx .pc-tg{display:flex;align-items:center;justify-content:space-between;padding:8px 0;cursor:pointer;font-size:13px}
  #lbx .pc-tg+.pc-tg{border-top:1px solid rgba(255,255,255,.07)}
  #lbx .pc-sw{width:36px;height:21px;border-radius:99px;background:#2a2a2a;border:1px solid rgba(255,255,255,.08);position:relative;flex:0 0 auto}
  #lbx .pc-sw::after{content:'';position:absolute;top:2px;left:2px;width:15px;height:15px;border-radius:50%;background:#6b6b6b;transition:.13s}
  #lbx .pc-tg.on .pc-sw{background:#BEF264;border-color:#BEF264}
  #lbx .pc-tg.on .pc-sw::after{left:18px;background:#0c0c0c}
  #lbx .pc-tg.dim{opacity:.35;pointer-events:none}
  #lbx .pc-send{display:flex;gap:6px}
  #lbx .pc-send button{flex:1;background:#0a0a0a;border:1px solid rgba(255,255,255,.08);color:rgba(255,255,255,.5);border-radius:9px;padding:9px;font-size:12.5px;cursor:pointer}
  #lbx .pc-send button.on{background:#191919;border-color:rgba(255,255,255,.14);color:#f0f0f0}
  #lbx .pc-input{width:100%;background:#0a0a0a;border:1px solid rgba(255,255,255,.08);color:#f0f0f0;border-radius:9px;padding:10px 12px;font-size:13px}
  #lbx .pc-go{width:100%;margin-top:12px;background:#BEF264;color:#0c0c0c;border:0;border-radius:10px;padding:13px;font-size:14px;font-weight:600;cursor:pointer}
  #lbx .pc-preview{background:repeating-linear-gradient(45deg,#0e0e0e 0 12px,#0b0b0b 12px 24px);border-left:1px solid rgba(255,255,255,.08);padding:14px;display:flex;flex-direction:column}
  #lbx .pc-pv-head{font-size:11px;color:rgba(255,255,255,.5);margin-bottom:8px}
  #lbx #pc-frame{flex:1;width:100%;min-height:0;border:0;border-radius:8px;background:#fff}

  .lbx-overlay { position: fixed; inset: 0; z-index: 300; background: rgba(0,0,0,.6); display: flex; align-items: center; justify-content: center; }
  .lbx-overlay[hidden] { display: none; }
  #lbx .lbx-dialog { display: block; width: min(1040px, 95vw); }
  #lbx .lbx-body { grid-template-columns: 340px 1fr; height: min(780px, 86vh); }
  #lbx .pc-preview { min-height: 0; }
  #lbx #lbx-frame { flex: 1; width: 100%; min-height: 0; border: 0; border-radius: 8px; background: #d9d9d6; }
  #lbx .lbx-hint { font-size: 11.5px; line-height: 1.5; color: rgba(255,255,255,.45); margin-top: 8px; }
  #lbx .lbx-hint b { color: rgba(255,255,255,.8); font-weight: 600; }
  #lbx .lbx-total { color: #BEF264; letter-spacing: 0; text-transform: none; font-size: 11px; }
  #lbx .lbx-saved { color: rgba(255,255,255,.3); letter-spacing: 0; text-transform: none; font-size: 11px; font-weight: 400; }
  #lbx .lbx-setn { display: flex; align-items: center; gap: 6px; margin-top: 8px; }
  #lbx .lbx-setn[hidden] { display: none; }
  #lbx .lbx-setn button, #lbx .lbx-q button { width: 30px; height: 30px; background: #0a0a0a; border: 1px solid rgba(255,255,255,.08); color: #f0f0f0; border-radius: 8px; cursor: pointer; font-size: 15px; line-height: 1; padding: 0; }
  #lbx .lbx-setn input, #lbx .lbx-q input { width: 54px; height: 30px; text-align: center; background: #0a0a0a; border: 1px solid rgba(255,255,255,.08); color: #f0f0f0; border-radius: 8px; font: inherit; font-size: 13px; -moz-appearance: textfield; padding: 0; }
  #lbx .lbx-setn input::-webkit-inner-spin-button, #lbx .lbx-q input::-webkit-inner-spin-button { -webkit-appearance: none; }
  #lbx .lbx-setn span { font-size: 12px; color: rgba(255,255,255,.45); }
  #lbx .lbx-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 4px 8px; align-items: center; padding: 8px 0; border-top: 1px solid rgba(255,255,255,.06); }
  #lbx .lbx-row:first-child { border-top: 0; padding-top: 0; }
  #lbx .lbx-row .n { font-size: 12.5px; color: #f0f0f0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  #lbx .lbx-row .c { grid-column: 1; font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 11px; color: rgba(255,255,255,.45); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  #lbx .lbx-row .c i { font-style: normal; font-family: Inter, system-ui, sans-serif; color: #FAB46A; margin-left: 4px; }
  #lbx .lbx-row .q { grid-row: 1 / span 2; grid-column: 2; }
  #lbx .lbx-q { display: flex; align-items: center; gap: 4px; }
  #lbx .lbx-q button { width: 24px; height: 26px; font-size: 13px; }
  #lbx .lbx-q input { width: 40px; height: 26px; font-size: 12.5px; }
  #lbx .lbx-row.zero .n, #lbx .lbx-row.zero .c { opacity: .4; }
  #lbx .lbx-row.edited .lbx-q input { border-color: rgba(190,242,100,.5); }
  #lbx .lbx-row .x { grid-column: 1 / -1; font-size: 11px; color: rgba(255,255,255,.35); }
  #lbx .lbx-sheetpick { display: grid; grid-template-columns: repeat(3, 1fr); gap: 3px; width: 150px; }
  #lbx .lbx-sheetpick button { height: 12px; border-radius: 2px; border: 0; background: #2a2a2a; cursor: pointer; padding: 0; }
  #lbx .lbx-sheetpick button.used { background: #121212; outline: 1px dashed rgba(255,255,255,.15); outline-offset: -1px; }
  #lbx .lbx-sheetpick button.start { background: #BEF264; }
  #lbx .lbx-legend { font-size: 11.5px; line-height: 1.5; color: rgba(255,255,255,.4); margin-top: 10px; }
  #lbx .pc-seg button small { display: block; font-size: 10.5px; font-weight: 400; opacity: .7; margin-top: 1px; white-space: normal; }
  #lbx .pc-seg.pc-col button { text-align: left; }
  #lbx .pc-go[disabled] { opacity: .4; cursor: not-allowed; }
  #lbx .lbx-err { font-size: 12.5px; color: #ff9a9a; padding: 6px 0; }
  @media (max-width: 720px) {
    #lbx .lbx-body { grid-template-columns: 1fr; height: 88vh; overflow: auto; }
    #lbx .pc-preview { height: 280px; border-left: 0; border-top: 1px solid rgba(255,255,255,.08); }
  }
</style>

<script>
(function () {
  if (window.openLabels) return;
  var root = document.getElementById('lbx');
  if (!root) return;
  var DATA_URL  = @json(route('tenant.inventory.labels.data'));
  var PREFS_URL = @json(route('tenant.inventory.labels.prefs'));
  var $ = function (id) { return document.getElementById(id); };
  var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var token = function () { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; };
  var S = null, timer = null;

  function isUpc(s) {
    if (!/^\d{12}$/.test(s)) return false;
    var t = 0; for (var i = 0; i < 11; i++) t += (+s[i]) * (i % 2 === 0 ? 3 : 1);
    return (10 - t % 10) % 10 === +s[11];
  }
  function qtyOf(it) {
    if (S.over[it.id] !== undefined) return S.over[it.id];
    if (S.mode === 'rcv') return it.received || 0;
    if (S.mode === 'stock') return Math.max(0, it.stock || 0);
    return S.n;
  }
  function codeOf(it) {
    var v = it[S.code];
    return v ? { v: v, note: (S.code === 'upc' && !isUpc(v)) ? 'not a standard UPC — prints as Code 128' : '' }
             : { v: it.sku, note: 'no ' + S.code.toUpperCase() + ' — uses SKU' };
  }

  function sync() {
    $('lbx-code').querySelectorAll('button').forEach(function (b) { b.classList.toggle('on', b.dataset.v === S.code); });
    var miss = S.items.filter(function (it) { return !it[S.code]; }).length;
    $('lbx-code-hint').innerHTML = (S.code === 'upc' ? 'Prints as a UPC-A barcode that any scanner and the register read.' : 'Prints as a Code 128 barcode. The register finds an item by its ' + S.code.toUpperCase() + ' when scanned.')
      + (miss ? ' <b>' + miss + ' item' + (miss > 1 ? 's have' : ' has') + ' no ' + S.code.toUpperCase() + '</b> and will use the SKU instead.' : '');

    var modes = (S.from === 'shipment' ? [['rcv', 'Received in this shipment', 'One label for each unit that came in']] : [])
      .concat([['stock', 'In stock', 'One label for each unit on hand' + (S.from === 'shipment' ? ' at this shipment’s location' : ' here')], ['set', 'Set number', 'The same number of each item']]);
    $('lbx-qty').innerHTML = modes.map(function (m) { return '<button type="button" data-v="' + m[0] + '" class="' + (S.mode === m[0] ? 'on' : '') + '">' + m[1] + '<small>' + m[2] + '</small></button>'; }).join('');
    $('lbx-setn').hidden = S.mode !== 'set'; $('lbx-n').value = S.n;

    var total = 0;
    $('lbx-items').innerHTML = S.items.map(function (it) {
      var q = qtyOf(it), c = codeOf(it); total += q;
      return '<div class="lbx-row' + (q === 0 ? ' zero' : '') + (S.over[it.id] !== undefined ? ' edited' : '') + '">'
        + '<div class="n" title="' + esc(it.name) + '">' + esc(it.name) + '</div>'
        + '<div class="q"><div class="lbx-q"><button type="button" data-qd="-1" data-id="' + esc(it.id) + '" aria-label="Fewer">−</button><input type="number" min="0" max="999" data-qi="' + esc(it.id) + '" value="' + q + '" aria-label="Labels"><button type="button" data-qd="1" data-id="' + esc(it.id) + '" aria-label="More">+</button></div></div>'
        + '<div class="c">' + esc(c.v) + (c.note ? '<i>' + esc(c.note) + '</i>' : '') + '</div>'
        + (q === 0 && S.over[it.id] === undefined ? '<div class="x">' + (S.mode === 'rcv' ? 'none received — skipped' : S.mode === 'stock' ? 'none in stock — skipped' : '') + '</div>' : '')
        + '</div>';
    }).join('')
      + (S.skipped ? '<div class="lbx-hint">' + S.skipped + ' line' + (S.skipped > 1 ? 's aren’t' : ' isn’t') + ' linked to an inventory item yet, so ' + (S.skipped > 1 ? 'they' : 'it') + ' can’t get a label.</div>' : '')
      + (Object.keys(S.over).length ? '<div class="lbx-hint">Changed numbers are outlined. <a href="#" data-reset style="color:#BEF264">Reset</a></div>' : '')
      + (S.items.length ? '' : '<div class="lbx-hint">No items to label.</div>');
    $('lbx-total').textContent = total + ' label' + (total === 1 ? '' : 's');

    root.querySelectorAll('[data-inc]').forEach(function (t) { t.classList.toggle('on', S.inc.indexOf(t.dataset.inc) >= 0); });

    var st = S.stocks[S.stock];
    $('lbx-stock').innerHTML = Object.keys(S.stocks).map(function (k) { var s = S.stocks[k]; return '<button type="button" data-v="' + k + '" class="' + (S.stock === k ? 'on' : '') + '">' + esc(s.name) + '<small>' + esc(s.sub) + '</small></button>'; }).join('');
    $('lbx-start').hidden = st.roll;
    if (!st.roll) $('lbx-sheetpick').innerHTML = Array.from({ length: 30 }, function (_, i) { return '<button type="button" data-start="' + i + '" class="' + (i < S.start ? 'used' : (i === S.start ? 'start' : '')) + '" title="Label ' + (i + 1) + '"></button>'; }).join('');

    var go = $('lbx-go');
    go.disabled = !total;
    go.textContent = total ? 'Print ' + total + ' label' + (total === 1 ? '' : 's') : 'Nothing to print';
    var pages = st.roll ? total : Math.ceil((total + S.start) / 30);
    $('lbx-pv-head').textContent = 'Preview · ' + st.name + ' · ' + (st.roll ? total + ' label' + (total === 1 ? '' : 's') + ' on the roll' : pages + ' sheet' + (pages === 1 ? '' : 's'));
    S.total = total;
    clearTimeout(timer); timer = setTimeout(preview, 220);
  }

  function fillForm() {
    var f = $('lbx-form');
    f.querySelectorAll('input:not([name="_token"])').forEach(function (i) { i.remove(); });
    var add = function (k, v) { var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; f.appendChild(i); };
    add('code', S.code); add('stock', S.stock); add('start', S.start);
    S.inc.forEach(function (k) { add('inc[]', k); });
    S.items.forEach(function (it) { var q = qtyOf(it); if (q > 0) add('q[' + it.id + ']', q); });
    return f;
  }
  function preview() { fillForm().submit(); }

  function savePrefs() {
    var fd = new FormData();
    fd.append('_token', token()); fd.append('code', S.code); fd.append('stock', S.stock);
    S.inc.forEach(function (k) { fd.append('inc[]', k); });
    fetch(PREFS_URL, { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } }).catch(function () {});
  }

  window.openLabels = function (src) {
    root.hidden = false;
    document.body.style.overflow = 'hidden';
    $('lbx-ctx').textContent = 'Loading…';
    $('lbx-items').innerHTML = '';
    var q = new URLSearchParams();
    if (src.shipment) q.set('shipment', src.shipment);
    (src.items || []).forEach(function (id) { q.append('items[]', id); });
    fetch(DATA_URL + '?' + q.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) {
        S = { from: d.from, items: d.items, skipped: d.skipped || 0, stocks: d.stocks,
              code: d.prefs.code, stock: d.prefs.stock, inc: d.prefs.inc.slice(),
              mode: d.from === 'shipment' ? 'rcv' : 'set', n: 1, over: {}, start: 0, total: 0 };
        $('lbx-ctx').textContent = d.context;
        sync();
      })
      .catch(function () { $('lbx-ctx').textContent = ''; $('lbx-items').innerHTML = '<div class="lbx-err">Couldn’t load the items. Close this and try again.</div>'; });
  };
  function close() { root.hidden = true; document.body.style.overflow = ''; }

  root.addEventListener('click', function (e) {
    if (e.target === root || e.target.closest('[data-lbx-close]')) { close(); return; }
    if (!S) return;
    var r = e.target.closest('[data-reset]');
    if (r) { e.preventDefault(); S.over = {}; sync(); return; }
    var t = e.target.closest('label.pc-tg');
    if (t) { e.preventDefault(); var k = t.dataset.inc, i = S.inc.indexOf(k); if (i >= 0) S.inc.splice(i, 1); else S.inc.push(k); sync(); return; }
    var b = e.target.closest('button'); if (!b) return;
    var p = b.parentNode && b.parentNode.id;
    if (p === 'lbx-code') { S.code = b.dataset.v; sync(); return; }
    if (p === 'lbx-qty') { S.mode = b.dataset.v; S.over = {}; sync(); return; }
    if (p === 'lbx-stock') { S.stock = b.dataset.v; S.start = 0; sync(); return; }
    if (b.dataset.start !== undefined) { S.start = +b.dataset.start; sync(); return; }
    if (b.dataset.d) { S.n = Math.max(1, Math.min(999, S.n + +b.dataset.d)); S.over = {}; sync(); return; }
    if (b.dataset.qd) {
      var it = S.items.find(function (x) { return String(x.id) === b.dataset.id; });
      if (it) { S.over[it.id] = Math.max(0, Math.min(999, qtyOf(it) + +b.dataset.qd)); sync(); }
      return;
    }
    if (b.id === 'lbx-go' && S.total) {
      savePrefs();
      // print exactly what the preview shows
      clearTimeout(timer);
      var fr = $('lbx-frame');
      fr.onload = function () { fr.onload = null; try { fr.contentWindow.focus(); fr.contentWindow.print(); } catch (err) {} };
      preview();
    }
  });
  root.addEventListener('change', function (e) {
    if (!S) return;
    if (e.target.id === 'lbx-n') { S.n = Math.max(1, Math.min(999, parseInt(e.target.value, 10) || 1)); S.over = {}; sync(); return; }
    if (e.target.dataset.qi) { S.over[e.target.dataset.qi] = Math.max(0, Math.min(999, parseInt(e.target.value, 10) || 0)); sync(); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !root.hidden) close(); });
  // after printing, focus sits in the preview frame; Esc still closes
  $('lbx-frame').addEventListener('load', function () {
    try { this.contentDocument.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !root.hidden) close(); }); } catch (err) {}
  });
})();
</script>
@endonce
