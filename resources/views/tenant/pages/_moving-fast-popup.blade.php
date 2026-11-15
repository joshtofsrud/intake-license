{{--
  MARKER-MOVING-FAST: the Moving fast popup. Opened from the section's inspector (or by clicking the
  section on the canvas). Works on a copy of the section's fields; "Save section" writes them back
  into the inspector's hidden fields and saves through the builder's own Save (pb2SaveNow), so
  revisions, drafts and the preview behave as for every other section.
--}}
<style>
.pb2-gallery-card--off{opacity:.5;cursor:default}
.pb2-mf-open{width:100%;justify-content:center;margin-bottom:12px}
.pb2-mf-legend{border:1px solid var(--pb2-border-2,rgba(255,255,255,.16));border-radius:10px;padding:10px 12px;font-size:12px;color:var(--pb2-text-dim,rgba(255,255,255,.6));line-height:1.55;margin-bottom:12px}
.pb2-mf-legend b{color:var(--pb2-text,#f5f5f4);font-weight:600}
.pb2-mf-legend a{color:inherit;text-decoration:underline}
.pb2-mf-sum{border:1px solid var(--pb2-border-2,rgba(255,255,255,.16));border-radius:10px;overflow:hidden;margin-bottom:10px}
.pb2-mf-sum .row{display:flex;justify-content:space-between;gap:10px;padding:8px 12px;border-bottom:1px solid var(--pb2-border,rgba(255,255,255,.08));font-size:12.5px}
.pb2-mf-sum .row:last-child{border-bottom:0}.pb2-mf-sum .row span:first-child{color:var(--pb2-text-dim,rgba(255,255,255,.6))}.pb2-mf-sum .row span:last-child{text-align:right}
.pb2-mf-locked{border:1px dashed var(--pb2-border-2,rgba(255,255,255,.2));border-radius:10px;padding:13px;font-size:12.5px;color:var(--pb2-text-dim,rgba(255,255,255,.6));line-height:1.55}
.pb2-mf-locked b{color:var(--pb2-text,#f5f5f4)}

.mfx{overflow:hidden;flex:none;position:fixed;inset:0;background:rgba(0,0,0,.62);z-index:9000;display:none;padding:22px;color:var(--pb2-text,#f5f5f4);font-family:var(--pb2-font,Inter,system-ui,sans-serif)}
.mfx.on{display:flex}
.mfx>.box{background:var(--pb2-surface,#141414);box-shadow:0 0 0 1px rgba(255,255,255,.1),0 20px 60px rgba(0,0,0,.6);border-radius:14px;flex:1;display:flex;flex-direction:column;min-height:0;overflow:hidden}
.mfx-top{display:flex;align-items:center;gap:14px;padding:14px 18px 0}
.mfx-top .ic{width:30px;height:30px;border-radius:8px;background:var(--pb2-surface-2,#1f1f1f);display:grid;place-items:center;color:var(--pb2-text-dim)}
.mfx-top h3{font-size:15px;font-weight:600;margin:0}.mfx-top .sub{font-size:12px;color:var(--pb2-text-dim);margin-top:1px}
.mfx-top .x{margin-left:auto;background:none;border:0;color:var(--pb2-text-dim);font-size:20px;cursor:pointer;padding:2px 8px}
.mfx-tabs{display:flex;gap:4px;padding:0 18px;border-bottom:1px solid var(--pb2-border);margin-top:10px}
.mfx-tab{background:none;border:0;color:var(--pb2-text-dim);font:500 13px inherit;padding:10px 12px;cursor:pointer;position:relative;font-family:inherit}
.mfx-tab.on{color:var(--pb2-text)}.mfx-tab.on:after{content:'';position:absolute;left:8px;right:8px;bottom:-1px;height:2px;background:var(--pb2-accent)}
.mfx-tab .c{font-size:11px;color:var(--pb2-text-dim);margin-left:4px}
.mfx-bar{display:flex;align-items:center;gap:12px;padding:10px 18px;border-bottom:1px solid var(--pb2-border);flex-wrap:wrap}
.mfx-bar .lbl{font-size:12px;color:var(--pb2-text-dim)}
.mfx-bar .pb2-input{width:240px}
.mfx-bar .pb2-seg{width:auto;display:inline-flex}.mfx-bar .pb2-seg-btn{flex:none;width:auto;padding:0 12px;white-space:nowrap}
.mfx-pane{flex:1;min-height:0;display:flex;flex-direction:column}.mfx-pane[hidden]{display:none}
.mfx-body{flex:1;display:grid;grid-template-columns:230px minmax(0,1fr) 290px;min-height:0}
.mfx-f{border-right:1px solid var(--pb2-border);overflow:auto;padding:4px 16px 18px}
.mfx-h{font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:var(--pb2-text-dim);font-weight:600;margin:16px 0 6px;display:flex;align-items:center;gap:8px}
.mfx-h a{margin-left:auto;text-transform:none;letter-spacing:0;font-weight:400;cursor:pointer;color:var(--pb2-text-dim);text-decoration:underline}
.mfx-ck{display:flex;align-items:center;gap:8px;font-size:13px;padding:4px 0;cursor:pointer}
.mfx-ck .n{margin-left:auto;font-size:11.5px;color:var(--pb2-text-dim)}
.mfx-ck input{accent-color:var(--pb2-accent)}
.mfx-tree .mfx-ck{padding-left:calc(var(--d,0) * 14px)}
.mfx-tree .tw{width:12px;color:var(--pb2-text-dim);font-size:10px;cursor:pointer;flex:none}
.mfx-pr{display:flex;gap:6px;align-items:center}.mfx-pr .pb2-input{width:100%}
.mfx-l{overflow:auto;position:relative}
.mfx-t{width:100%;border-collapse:collapse;font-size:13px}
.mfx-t th{position:sticky;top:0;background:var(--pb2-surface,#141414);text-align:left;font-weight:500;color:var(--pb2-text-dim);font-size:11.5px;padding:9px 10px;border-bottom:1px solid var(--pb2-border);white-space:nowrap;z-index:1}
.mfx-t th.so{cursor:pointer}.mfx-t th.so.on{color:var(--pb2-text)}
.mfx-t td{padding:9px 10px;border-bottom:1px solid var(--pb2-border);vertical-align:middle}
.mfx-t tbody tr{cursor:pointer}.mfx-t tbody tr:hover td{background:var(--pb2-surface-2)}
.mfx-t tr.sel td{background:rgba(198,244,50,.05)}
.mfx-t tr.open td{background:var(--pb2-surface-2)}
.mfx-im{width:40px;height:40px;border-radius:7px;background:#fff;display:grid;place-items:center;flex:none;overflow:hidden;color:#999;font-size:9px;text-align:center}
.mfx-im img{width:100%;height:100%;object-fit:contain;padding:3px}
.mfx-it{display:flex;gap:10px;align-items:center;min-width:200px}
.mfx-it .b{font-size:11px;color:var(--pb2-text-dim);text-transform:uppercase;letter-spacing:.04em}
.mfx-it .nm{font-size:13px;font-weight:500;line-height:1.3}
.mfx-cat{font-size:11.5px;color:var(--pb2-text-dim);margin-top:2px}
.mfx-in{font-size:11px;color:var(--pb2-accent);margin-top:2px}
.mfx-v{display:flex;flex-direction:column;gap:3px}
.mfx-vc{display:flex;gap:6px;align-items:center;font-size:12px;white-space:nowrap}
.mfx-vc .vn{font-weight:600;font-size:10.5px;padding:1px 6px;border-radius:5px;background:var(--pb2-surface-2,#2a2a2a);min-width:34px;text-align:center}
.mfx-vc .lo{color:#EF9F27}.mfx-vc .mu{color:var(--pb2-text-dim)}
.mfx-pace{display:flex;align-items:center;gap:7px;white-space:nowrap}
.mfx-pace .bar{width:54px;height:5px;border-radius:99px;background:var(--pb2-surface-2,#2a2a2a);overflow:hidden}.mfx-pace .bar i{display:block;height:100%;background:#7aa82a}
.mfx-pill{font-size:10.5px;font-weight:600;padding:1px 7px;border-radius:99px;white-space:nowrap}
.mfx-pill.g{background:rgba(99,153,34,.22);color:#97C459}.mfx-pill.a{background:rgba(239,159,39,.18);color:#EF9F27}.mfx-pill.n{background:var(--pb2-surface-2,#2a2a2a);color:var(--pb2-text-dim)}
.mfx-cb{width:16px;height:16px;accent-color:var(--pb2-accent)}
.mfx-more td{background:var(--pb2-surface-2)!important;cursor:default;padding:4px 14px 16px 60px}
.mfx-dt{display:grid;grid-template-columns:1.4fr 1fr 200px;gap:22px;font-size:12.5px}
.mfx-dt h5{font-size:10.5px;letter-spacing:.1em;text-transform:uppercase;color:var(--pb2-text-dim);font-weight:600;margin:10px 0 6px}
.mfx-dt table{width:100%;border-collapse:collapse}.mfx-dt td{white-space:nowrap;padding:5px 10px 5px 0;border-bottom:1px solid var(--pb2-border);background:none!important}
.mfx-dt .row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--pb2-border)}
.mfx-dt .row span:first-child{color:var(--pb2-text-dim)}
.mfx-spark{display:flex;align-items:flex-end;gap:2px;height:40px;margin-top:4px}.mfx-spark i{flex:1;background:#7aa82a;border-radius:2px 2px 0 0;opacity:.85;min-height:2px}
.mfx-empty{padding:40px 20px;text-align:center;color:var(--pb2-text-dim);font-size:13px;line-height:1.6}
.mfx-empty .pb2-btn{margin:4px}
.mfx-r{border-left:1px solid var(--pb2-border);display:flex;flex-direction:column;min-height:0}
.mfx-r .hd{padding:14px 16px 8px}.mfx-r .hd .t{font-size:13.5px;font-weight:600}.mfx-r .hd .d{font-size:12px;color:var(--pb2-text-dim);margin-top:3px;line-height:1.5}
.mfx-on{flex:1;overflow:auto;padding:0 10px}
.mfx-oi{display:flex;gap:8px;align-items:center;padding:7px 6px;border-bottom:1px solid var(--pb2-border);font-size:12.5px}
.mfx-oi[draggable="true"]{cursor:grab}.mfx-oi.drag{opacity:.4}
.mfx-oi .g{color:var(--pb2-text-dim);font-size:12px}
.mfx-oi .k{width:24px;font-size:11px;color:var(--pb2-text-dim);text-align:right}
.mfx-oi.pin .k{color:var(--pb2-accent);font-weight:700}
.mfx-oi .im{width:30px;height:30px;border-radius:5px;background:#fff;flex:none;display:grid;place-items:center;overflow:hidden}
.mfx-oi .im img{width:100%;height:100%;object-fit:contain;padding:2px}
.mfx-oi .nm{flex:1;min-width:0;line-height:1.3}.mfx-oi .nm small{display:block;color:var(--pb2-text-dim);font-size:11px}
.mfx-oi .rm{background:none;border:0;color:var(--pb2-text-dim);cursor:pointer;font-size:15px}
.mfx-oi.ghost{opacity:.45}
.mfx-r .ft{padding:12px 16px;border-top:1px solid var(--pb2-border);font-size:12px;color:var(--pb2-text-dim);line-height:1.5}
.mfx-r .ft select{width:auto;display:inline-block}
.mfx-w{flex:1;display:grid;grid-template-columns:380px minmax(0,1fr);min-height:0}
.mfx-wf{border-right:1px solid var(--pb2-border);overflow:auto;padding:6px 18px 18px}
.mfx-wf .pb2-group{padding:14px 0;border-bottom:1px solid var(--pb2-border)}
.mfx textarea.pb2-input{height:auto;min-height:78px;padding-top:8px}
.mfx-wp{overflow:auto;background:#0a0a0a;padding:18px}
.mfx-wp iframe{width:100%;border:0;border-radius:10px;display:block}
.mfx-wp .cap{font-size:11.5px;color:var(--pb2-text-dim);margin-bottom:8px}
.mfx-foot{display:flex;align-items:center;gap:10px;padding:12px 18px;border-top:1px solid var(--pb2-border)}
.mfx-foot .pb2-btn:disabled{opacity:.45;cursor:default}.mfx-im span,.mfx-oi .im span{font-size:7px;line-height:1.1;padding:2px;color:#999}
.mfx-foot .st{font-size:12.5px;color:var(--pb2-text-dim);margin-right:auto}
.mfx-load{position:absolute;inset:0;display:grid;place-items:center;color:var(--pb2-text-dim);font-size:13px;background:rgba(0,0,0,.25)}
@media(max-width:1440px){.mfx-body{grid-template-columns:200px minmax(0,1fr) 250px}.mfx-pace .bar{display:none}.mfx-it{min-width:170px}.mfx-t td,.mfx-t th{padding-left:7px;padding-right:7px}.mfx-vc .mu{display:none}}
@media(max-width:900px){.mfx{padding:0}.mfx-body{grid-template-columns:1fr}.mfx-f,.mfx-r{display:none}.mfx-w{grid-template-columns:1fr}.mfx-wp{display:none}}
</style>

<div class="mfx pb2-insp-body" id="pb2-mf" aria-modal="true" role="dialog" aria-label="Moving fast">
  <div class="box">
    <div class="mfx-top"><span class="ic"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><polyline points="3 17 9 11 13 15 21 7"/><polyline points="15 7 21 7 21 13"/></svg></span>
      <div><h3>Moving fast</h3><div class="sub">Items moving at your vendors right now, whether or not you carry them. Updated every few hours from your vendors' stock.</div></div>
      <button type="button" class="x" data-mfx-close title="Close">×</button></div>
    <div class="mfx-tabs"><button type="button" class="mfx-tab on" data-mfx-tab="items">Items <span class="c" id="mfx-tabc"></span></button><button type="button" class="mfx-tab" data-mfx-tab="words">Words and layout</button></div>

    <div class="mfx-pane" id="mfx-items">
      <div class="mfx-bar">
        <span class="lbl">Show</span>
        <div class="pb2-seg" id="mfx-list">@foreach (\App\Support\MovingFast::LISTS as $k => [$label]) <button type="button" class="pb2-seg-btn" data-l="{{ $k }}">{{ $label }}</button>@endforeach</div>
        <input type="search" class="pb2-input" id="mfx-q" placeholder="Search name, brand or part number">
        <span style="flex:1"></span>
        <span class="lbl">Fill the section</span>
        <div class="pb2-seg" id="mfx-mode"><button type="button" class="pb2-seg-btn" data-m="auto">Automatically</button><button type="button" class="pb2-seg-btn" data-m="hand">I pick the items</button></div>
      </div>
      <div class="mfx-body">
        <div class="mfx-f" id="mfx-f"></div>
        <div class="mfx-l" id="mfx-l"></div>
        <div class="mfx-r"><div class="hd"><div class="t" id="mfx-ont"></div><div class="d" id="mfx-ond"></div></div><div class="mfx-on" id="mfx-on"></div><div class="ft" id="mfx-onft"></div></div>
      </div>
    </div>

    <div class="mfx-pane" id="mfx-words" hidden>
      <div class="mfx-w">
        <div class="mfx-wf" id="mfx-wf"></div>
        <div class="mfx-wp"><div class="cap">How shoppers see it, with your template's colours and fonts</div><iframe id="mfx-pv" title="Preview"></iframe></div>
      </div>
    </div>

    <div class="mfx-foot"><span class="st" id="mfx-st"></span>
      <button type="button" class="pb2-btn" data-mfx-close>Cancel</button>
      <button type="button" class="pb2-btn pb2-btn-primary" id="mfx-save">Save section</button></div>
  </div>
</div>

<script>
(function () {
  var EP = { items: @json(route('tenant.pages.moving-fast.items')), detail: @json(route('tenant.pages.moving-fast.detail')), add: @json(route('tenant.pages.moving-fast.add')), inv: @json(url('/admin/inventory')) };
  var LISTS = @json(collect(\App\Support\MovingFast::LISTS)->map(fn ($l) => ['name' => $l[0], 'why' => $l[1]]));
  var $ = function (id) { return document.getElementById(id); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
  var money = function (c) { return c == null ? '' : '$' + (c % 100 === 0 ? (c / 100).toLocaleString() : (c / 100).toFixed(2)); };
  var toast = function (m, bad) { if (window.IntakeToast) (bad ? IntakeToast.error : IntakeToast.success)(m); };
  var csrf = function () { var m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; };

  var POOL = [], VEND = [], SET = { few_left: 7, back_days: 14, new_days: 30, fast_week: 10 }, SHOP = '', SPECIAL = 'Special order — usually a few days';
  var orig = null, ch = null, openRow = null, sortK = null, openCat = {}, loadedList = null, loading = false, weeks = {};

  // ------------------------------------------------------------ the section's fields in the inspector
  var STR = ['eyebrow', 'heading', 'body', 'btn_label', 'btn_url', 'mf_list', 'mf_mode', 'mf_count', 'mf_layout', 'mf_carry', 'mf_min', 'mf_max', 'mf_badges', 'mf_prices', 'mf_special'];
  var ARR = ['mf_picked', 'mf_vendors', 'mf_cats', 'mf_brands'];
  function field(f) { var b = $('pb2-insp-body'); return b ? b.querySelector('[data-mf-fields] [data-field="' + f + '"]') : null; }
  function readFields() {
    var c = {};
    STR.forEach(function (f) { var el = field(f); c[f] = el ? el.value : ''; });
    ARR.forEach(function (f) { var el = field(f); try { c[f] = el && el.value ? JSON.parse(el.value) : []; } catch (e) { c[f] = []; } if (!Array.isArray(c[f])) c[f] = []; });
    return {
      eyebrow: c.eyebrow, heading: c.heading, body: c.body, btnLabel: c.btn_label, btnUrl: c.btn_url,
      list: LISTS[c.mf_list] ? c.mf_list : 'fast', mode: c.mf_mode === 'hand' ? 'hand' : 'auto',
      count: [4, 8, 12].indexOf(+c.mf_count) >= 0 ? +c.mf_count : 8, layout: c.mf_layout === 'row' ? 'row' : 'grid',
      carry: ['all', 'stock', 'in', 'none'].indexOf(c.mf_carry) >= 0 ? c.mf_carry : 'all',
      vend: c.mf_vendors.map(String), cats: c.mf_cats.map(String), brands: c.mf_brands.map(String), picked: c.mf_picked.map(String),
      min: c.mf_min, max: c.mf_max, badges: c.mf_badges !== '0', prices: c.mf_prices !== '0', special: c.mf_special === 'hide' ? 'hide' : 'show'
    };
  }
  function writeFields(s) {
    var set = function (f, v) { var el = field(f); if (el) el.value = v; };
    set('eyebrow', s.eyebrow); set('heading', s.heading); set('body', s.body); set('btn_label', s.btnLabel); set('btn_url', s.btnUrl);
    set('mf_list', s.list); set('mf_mode', s.mode); set('mf_count', String(s.count)); set('mf_layout', s.layout); set('mf_carry', s.carry);
    set('mf_min', s.min); set('mf_max', s.max); set('mf_badges', s.badges ? '1' : '0'); set('mf_prices', s.prices ? '1' : '0'); set('mf_special', s.special);
    set('mf_picked', JSON.stringify(s.picked)); set('mf_vendors', JSON.stringify(s.vend)); set('mf_cats', JSON.stringify(s.cats)); set('mf_brands', JSON.stringify(s.brands));
  }

  // ------------------------------------------------------------ the same rules as App\Support\MovingFast
  function vendOn(s) { return s.vend.length ? s.vend : VEND; }
  function price(p) { return p.price == null ? null : p.price / 100; }
  function matches(s, p, q) {
    var you = p.you;
    if (s.carry === 'stock' && !you) return false;
    if (s.carry === 'in' && (!you || you.st <= 0)) return false;
    if (s.carry === 'none' && you) return false;
    var vs = vendOn(s); if (!Object.keys(p.v).some(function (c) { return vs.indexOf(c) >= 0; })) return false;
    if (s.cats.length && !s.cats.some(function (c) { return p.cat === c || p.top === c || p.cat.indexOf(c + ' › ') === 0; })) return false;
    if (s.brands.length && s.brands.indexOf(p.brand) < 0) return false;
    var pr = price(p);
    if (pr == null) return false;
    if (s.min !== '' && s.min != null && !isNaN(+s.min) && pr < +s.min) return false;
    if (s.max !== '' && s.max != null && !isNaN(+s.max) && pr > +s.max) return false;
    if (s.special === 'hide' && !you) return false;
    if (you && !you.online) return false;
    if (q) { q = q.toLowerCase(); if ((p.name + ' ' + p.brand + ' ' + p.pn).toLowerCase().indexOf(q) < 0) return false; }
    return true;
  }
  function order(arr, k) {
    var big = 1e9, f = {
      fast: function (a, b) { return b.wk - a.wk; },
      low: function (a, b) { return (a.left == null ? big : a.left) - (b.left == null ? big : b.left); },
      back: function (a, b) { return (a.back == null ? big : a.back) - (b.back == null ? big : b.back); },
      'new': function (a, b) { return (a.seen == null ? big : a.seen) - (b.seen == null ? big : b.seen); },
      price: function (a, b) { return (a.price || 0) - (b.price || 0); }
    }[k] || function (a, b) { return b.wk - a.wk; };
    return arr.slice().sort(f);
  }
  function byKey(k) { for (var i = 0; i < POOL.length; i++) if (POOL[i].key === k) return POOL[i]; return null; }
  function shown(s) {
    if (s.mode === 'hand') return s.picked.map(byKey).filter(function (p) { return p && p.price != null && (s.special !== 'hide' || p.you) && (!p.you || p.you.online); }).slice(0, s.count);
    var pins = s.picked.map(byKey).filter(function (p) { return p && matches(s, p); });
    var rest = order(POOL.filter(function (p) { return !p.other && s.picked.indexOf(p.key) < 0 && matches(s, p); }), s.list);
    return pins.concat(rest).slice(0, s.count);
  }
  function badges(p, s) {
    var b = [];
    if (s.badges) {
      if (s.list === 'back' && p.back != null && p.back <= SET.back_days) b.push(['Back in stock', '']);
      else if (s.list === 'new' && p.seen != null && p.seen <= SET.new_days) b.push(['New', '']);
      else if (p.wk >= SET.fast_week) b.push(['Selling fast', '']);
      if (!(p.you && p.you.st > 0) && p.left != null && p.left <= SET.few_left) b.push(['Few left', '']);
    }
    if (!(p.you && p.you.st > 0)) b.push(['Special order', 'so']);
    return b;
  }
  var img = function (p, cls) { return p.img ? '<img src="' + esc(p.img) + '" alt="" loading="lazy">' : '<span>' + esc(SHOP) + '</span>'; };

  // ------------------------------------------------------------ open / close / load
  function open() {
    if (!$('pb2-insp-body') || !field('mf_list')) return;
    orig = readFields(); ch = JSON.parse(JSON.stringify(orig)); openRow = null; sortK = null; $('mfx-q').value = '';
    tab('items'); $('pb2-mf').classList.add('on'); document.body.style.overflow = 'hidden';
    load(ch.list);
  }
  function close(force) {
    if (!force && dirty()) {
      if (window.IntakeConfirm) { IntakeConfirm.show({ title: 'Close without saving?', message: 'Your changes in Moving fast are not saved yet.', confirmText: 'Close without saving', danger: true }).then(function (ok) { if (ok) close(true); }); return; }
    }
    $('pb2-mf').classList.remove('on'); document.body.style.overflow = '';
  }
  function dirty() { return ch && orig && JSON.stringify(ch) !== JSON.stringify(orig); }
  function load(list) {
    loading = true; render();
    var u = new URL(EP.items, location.origin); u.searchParams.set('list', list);
    ch.picked.forEach(function (k) { u.searchParams.append('keep[]', k); });
    fetch(u, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) { POOL = d.items || []; VEND = d.vendors || []; SET = d.settings || SET; SHOP = (d.shop || {}).name || ''; SPECIAL = (d.shop || {}).special || SPECIAL; loadedList = d.list; loading = false; render(); })
      .catch(function () { loading = false; POOL = []; render(); toast('Could not load items from your vendors. Try again in a moment.', true); });
  }

  // ------------------------------------------------------------ rendering
  var cnt = function (f) { return POOL.filter(f).length; };
  function renderF() {
    var s = ch, base = function (p) { return matches(Object.assign({}, s, { vend: [], cats: [], brands: [] }), p, ''); };
    var cats = {}; POOL.forEach(function (p) { if (p.other) return; var parts = p.cat.split(' › '), t = parts[0], c = parts.slice(0, 2).join(' › '); cats[t] = cats[t] || {}; if (parts.length > 1) cats[t][c] = (cats[t][c] || 0) + (base(p) ? 1 : 0); cats[t]['__'] = (cats[t]['__'] || 0) + (base(p) ? 1 : 0); });
    var brands = Array.from(new Set(POOL.filter(function (p) { return !p.other; }).map(function (p) { return p.brand; }).filter(Boolean))).sort();
    var ck = function (g, v, l, n, on, d) { return '<label class="mfx-ck" style="--d:' + (d || 0) + '">' + (d === 0 ? '<span class="tw" data-tw="' + esc(v) + '">' + (openCat[v] ? '▾' : '▸') + '</span>' : '') + '<input type="checkbox" data-' + g + '="' + esc(v) + '"' + (on ? ' checked' : '') + '>' + esc(l) + '<span class="n">' + n + '</span></label>'; };
    var h = '<div class="mfx-h">What you carry</div>' + [['all', 'Everything'], ['stock', 'Items you carry'], ['in', 'In stock with you'], ['none', "Items you don't carry"]].map(function (x) {
      return '<label class="mfx-ck"><input type="radio" name="mfx-carry" data-carry="' + x[0] + '"' + (s.carry === x[0] ? ' checked' : '') + '>' + x[1] + '<span class="n">' + cnt(function (p) { return !p.other && matches(Object.assign({}, s, { carry: x[0] }), p, ''); }) + '</span></label>'; }).join('');
    h += '<div class="mfx-h">Vendor</div>' + (VEND.length ? VEND.map(function (c) { return ck('vend', c, c, cnt(function (p) { return !p.other && p.v[c] && matches(Object.assign({}, s, { vend: [c] }), p, ''); }), vendOn(s).indexOf(c) >= 0); }).join('') : '<div class="mfx-cat">No vendors linked.</div>');
    h += '<div class="mfx-h">Vendor category' + (s.cats.length ? '<a data-clear="cats">Clear</a>' : '') + '</div><div class="mfx-tree">' + Object.keys(cats).sort().map(function (t) {
      return ck('cat', t, t, cats[t]['__'] || 0, s.cats.indexOf(t) >= 0, 0) + (openCat[t] ? Object.keys(cats[t]).filter(function (k) { return k !== '__'; }).sort().map(function (c) { return ck('cat', c, c.split(' › ')[1], cats[t][c], s.cats.indexOf(c) >= 0 || s.cats.indexOf(t) >= 0, 1); }).join('') : '');
    }).join('') + '</div>';
    h += '<div class="mfx-h">Brand' + (s.brands.length ? '<a data-clear="brands">Clear</a>' : '') + '</div>' + brands.map(function (b) { return ck('brand', b, b, cnt(function (p) { return !p.other && p.brand === b && base(p); }), s.brands.indexOf(b) >= 0); }).join('');
    h += '<div class="mfx-h">Your price</div><div class="mfx-pr"><input class="pb2-input" inputmode="decimal" placeholder="Min" data-min value="' + esc(s.min) + '"><span>to</span><input class="pb2-input" inputmode="decimal" placeholder="Max" data-max value="' + esc(s.max) + '"></div>';
    $('mfx-f').innerHTML = h;
  }
  function youCell(p) {
    if (p.you && !p.you.online) return '<span class="mfx-pill n">Off your online store</span><div class="mfx-cat">Turn on “Show online” to use it</div>';
    if (!p.you) return '<span class="mfx-pill n">Not carried</span><div class="mfx-cat">Sells as special order</div>';
    return p.you.st > 0 ? '<span class="mfx-pill g">' + p.you.st + ' in stock</span>' : '<span class="mfx-pill a">Out of stock</span>';
  }
  function vendCell(p) {
    return '<div class="mfx-v">' + Object.keys(p.v).map(function (c) { var x = p.v[c];
      return '<div class="mfx-vc"><span class="vn">' + esc(c) + '</span><span>' + x.wk + '/wk</span>' + (x.left != null ? '<span class="' + (x.left <= SET.few_left ? 'lo' : 'mu') + '">' + x.left + 'd left</span>' : '<span class="mu">' + (x.avail > 0 ? x.avail + ' on hand' : 'out') + '</span>') + (x.arrival ? '<span class="mu">· due ' + esc(x.arrival) + '</span>' : '') + '</div>'; }).join('') + '</div>';
  }
  function card(p, s) {
    var b = badges(p, s).map(function (x) { return '<span class="' + x[1] + '">' + esc(x[0]) + '</span>'; }).join('');
    var wh = p.you && p.you.st > 0 ? 'In stock' : SPECIAL;
    return '<div class="card"><div class="im">' + (p.img ? '<img src="' + esc(p.img) + '" alt="">' : '<span class="ph">' + esc(SHOP) + '</span>') + '<div class="bd">' + b + '</div></div><div class="tx"><div class="br">' + esc(p.brand) + '</div><div class="nm">' + esc(p.name) + '</div>' + (s.prices ? '<div class="pr">' + money(p.price) + '</div>' : '') + '<div class="wh">' + wh + '</div></div></div>';
  }
  function detail(p) {
    var w = weeks[p.key], mx = w ? Math.max.apply(null, w.concat([1])) : 1;
    return '<tr class="mfx-more"><td colspan="6"><div class="mfx-dt"><div><h5>At each vendor</h5><table>' + Object.keys(p.v).map(function (c) { var x = p.v[c];
      return '<tr><td><b>' + esc(c) + '</b></td><td>' + x.wk + ' a week</td><td>' + (x.left != null ? x.left + ' days left' : (x.avail > 0 ? x.avail + ' on hand' : 'out of stock')) + '</td><td>' + (x.arrival ? 'next arrival ' + esc(x.arrival) : 'no arrival date') + '</td><td class="mfx-cat">' + (x.back != null ? 'restocked ' + x.back + 'd ago' : '') + '</td></tr>'; }).join('') + '</table>' +
      '<h5>Leaving vendors, last 8 weeks</h5>' + (w ? '<div class="mfx-spark">' + w.map(function (v) { return '<i style="height:' + Math.round(v / mx * 100) + '%" title="' + v + ' units"></i>'; }).join('') + '</div>' : '<div class="mfx-cat">Loading…</div>') + '</div>' +
      '<div><h5>With you</h5><div class="row"><span>Carried</span><span>' + (p.you ? 'Yes' : 'No') + '</span></div><div class="row"><span>On hand</span><span>' + (p.you ? p.you.st : '—') + '</span></div>' +
      '<div class="row"><span>' + (p.you ? 'Your price' : 'Price shoppers would pay') + '</span><span>' + money(p.price) + '</span></div><div class="row"><span>Part number</span><span>' + esc(p.pn) + '</span></div>' +
      (!p.you ? '<div class="mfx-cat" style="margin-top:8px">Not in your inventory. When it shows in the section it is added as a catalog item, so a shopper can order it as a special order.</div>' : '') +
      '<div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap">' + (!p.you ? '<button type="button" class="pb2-btn" data-add="' + esc(p.key) + '">Add to my inventory</button>' : '<a class="pb2-btn" target="_blank" href="' + esc(EP.inv + '/' + p.you.id + '/edit') + '">Open item</a>') + '</div></div>' +
      '<div><h5>Shoppers see</h5><iframe class="mfx-mini" data-mini="' + esc(p.key) + '" style="width:200px;height:330px;border:0;border-radius:10px"></iframe></div></div></td></tr>';
  }
  function renderL() {
    var s = ch, q = $('mfx-q').value.trim();
    if (loading) { $('mfx-l').innerHTML = '<div class="mfx-empty">Loading what is moving at your vendors…</div>'; return; }
    if (!VEND.length) { $('mfx-l').innerHTML = '<div class="mfx-empty"><b>No vendors linked.</b><br>Connect a distributor in Inventory › Connection &amp; sync and this fills with what is moving there.</div>'; return; }
    var rows = order(POOL.filter(function (p) { return (!p.other || s.picked.indexOf(p.key) >= 0) && matches(s, p, q); }), sortK || s.list);
    if (!rows.length) {
      var fx = [];
      if (s.carry !== 'all') fx.push('<button type="button" class="pb2-btn" data-fx="carry">Show items you don\'t carry too</button>');
      if (s.cats.length || s.brands.length) fx.push('<button type="button" class="pb2-btn" data-fx="cats">Clear category and brand</button>');
      if (s.min !== '' || s.max !== '') fx.push('<button type="button" class="pb2-btn" data-fx="price">Clear price</button>');
      if (s.list !== 'fast') fx.push('<button type="button" class="pb2-btn" data-fx="list">Show Selling fastest</button>');
      if (q) fx.push('<button type="button" class="pb2-btn" data-fx="q">Clear search</button>');
      $('mfx-l').innerHTML = '<div class="mfx-empty">Nothing matches these filters right now.<br><br>' + fx.join('') + '</div>'; return;
    }
    var top = s.mode === 'auto' ? shown(s).map(function (p) { return p.key; }) : [], sk = sortK || s.list, maxwk = Math.max.apply(null, POOL.map(function (p) { return p.wk; }).concat([1]));
    var th = function (k, t) { return '<th class="so' + (sk === k ? ' on' : '') + '" data-sort="' + k + '">' + t + (sk === k ? ' ↓' : '') + '</th>'; };
    $('mfx-l').innerHTML = '<table class="mfx-t"><thead><tr><th style="width:30px"></th><th>Item</th>' + th('fast', 'Leaving vendors') + th('low', 'At vendors') + th('price', 'Your price') + '<th>With you</th></tr></thead><tbody>' + rows.map(function (p) {
      var on = s.picked.indexOf(p.key) >= 0;
      return '<tr class="' + (on ? 'sel ' : '') + (openRow === p.key ? 'open' : '') + '" data-row="' + esc(p.key) + '"><td><input type="checkbox" class="mfx-cb" data-pk="' + esc(p.key) + '"' + (on ? ' checked' : '') + ' title="' + (s.mode === 'auto' ? 'Pin to the section' : 'Add to the section') + '"></td>' +
        '<td><div class="mfx-it"><span class="mfx-im">' + img(p) + '</span><div><div class="b">' + esc(p.brand) + '</div><div class="nm">' + esc(p.name) + '</div><div class="mfx-cat">' + esc(p.cat) + '</div>' + (top.indexOf(p.key) >= 0 ? '<div class="mfx-in">' + (on ? 'Pinned' : 'In the section') + '</div>' : '') + '</div></div></td>' +
        '<td><div class="mfx-pace"><span class="bar"><i style="width:' + Math.round(p.wk / maxwk * 100) + '%"></i></span>' + p.wk + '/wk</div></td><td>' + vendCell(p) + '</td>' +
        '<td>' + (p.you ? money(p.price) : '<span class="mfx-cat">Would be</span> ' + money(p.price)) + '</td><td>' + youCell(p) + '</td></tr>' + (openRow === p.key ? detail(p) : '');
    }).join('') + '</tbody></table>';
    document.querySelectorAll('#mfx-l [data-mini]').forEach(function (f) { var p = byKey(f.dataset.mini); if (p) fill(f, '<div class="mfs" style="padding:0;background:transparent"><div class="in">' + card(p, ch) + '</div></div>', true); });
  }
  function renderOn() {
    var s = ch, list = shown(s);
    $('mfx-ont').textContent = s.mode === 'auto' ? 'In the section now' : 'Your items';
    $('mfx-ond').innerHTML = s.mode === 'auto' ? 'The top ' + s.count + ' by <b>' + esc(LISTS[s.list].why) + '</b>, from ' + POOL.filter(function (p) { return !p.other && matches(s, p); }).length + ' matching items. Tick an item on the left to pin it here.' : 'Tick items on the left to add them. Drag to change the order. Up to ' + s.count + ' show.';
    var extra = s.mode === 'hand' ? s.picked.slice(s.count).map(byKey).filter(Boolean) : [];
    var row = function (p, k, ghost) { var pin = s.mode === 'auto' && s.picked.indexOf(p.key) >= 0, mine = s.picked.indexOf(p.key) >= 0;
      return '<div class="mfx-oi' + (pin ? ' pin' : '') + (ghost ? ' ghost' : '') + '"' + (mine ? ' draggable="true" data-drag="' + esc(p.key) + '"' : '') + '><span class="g">' + (mine ? '⋮⋮' : '') + '</span><span class="k">' + (ghost ? '–' : pin ? 'Pin' : k + 1) + '</span><span class="im">' + img(p) + '</span><span class="nm">' + esc(p.name) + '<small>' + (ghost ? 'Over the ' + s.count + '-item limit' : (p.you ? (p.you.st > 0 ? 'In stock' : 'Out of stock with you') : 'Special order') + ' · ' + money(p.price)) + '</small></span>' + (mine ? '<button type="button" class="rm" data-un="' + esc(p.key) + '" title="Remove">×</button>' : '') + '</div>'; };
    $('mfx-on').innerHTML = loading ? '' : (list.length ? list.map(function (p, k) { return row(p, k); }).join('') + extra.map(function (p) { return row(p, 0, true); }).join('') : '<div class="mfx-empty">' + (s.mode === 'hand' ? 'No items yet. Tick some on the left.' : 'Nothing matches. Loosen a filter on the left.') + '</div>');
    var so = list.filter(function (p) { return !p.you; }).length;
    $('mfx-onft').innerHTML = 'Show <select class="pb2-input" id="mfx-count">' + [4, 8, 12].map(function (c) { return '<option' + (s.count === c ? ' selected' : '') + '>' + c + '</option>'; }).join('') + '</select> items<div style="margin-top:8px">' +
      (so ? so + ' of these aren\'t in your inventory yet. They are added as catalog items when the section shows, and sell as special orders.' : 'Every item here is in your inventory.') + (s.mode === 'auto' ? ' The list refreshes as your vendors\' stock moves; an item that stops selling drops off.' : '') + '</div>';
  }
  var HEAD = '';
  function siteHead() {
    if (HEAD) return HEAD;
    try { var d = $('pb2-preview').contentDocument; d.querySelectorAll('head style, head link[rel="stylesheet"]').forEach(function (n) { HEAD += n.outerHTML; }); } catch (e) {}
    return HEAD;
  }
  var SITE_CSS = '.mfs{background:var(--p-bg);color:var(--p-text);padding:56px 24px;font-family:var(--p-font-body)}.mfs .in{max-width:var(--p-max,1200px);margin:0 auto}' +
    '.mfs .hdr{display:flex;align-items:flex-end;gap:24px;margin-bottom:24px}.mfs .eb{font-size:12px;letter-spacing:.18em;text-transform:uppercase;color:var(--p-accent);font-weight:600}' +
    '.mfs h2{font-family:var(--p-font-heading);font-weight:var(--p-heading-weight,700);text-transform:var(--p-heading-transform,none);font-size:clamp(28px,4vw,44px);line-height:1.02;margin:8px 0}' +
    '.mfs .sb{color:var(--p-muted);font-size:15px;max-width:640px;line-height:1.55;margin:0}.mfs .hdr a{margin-left:auto;color:var(--p-text);font-size:14px;font-weight:600;white-space:nowrap;border-bottom:1.5px solid var(--p-accent);padding-bottom:2px;text-decoration:none}' +
    '.mfs .grid{display:grid;grid-template-columns:repeat(var(--p-shop-cols,4),minmax(0,1fr));gap:16px}.mfs .grid.row{grid-template-columns:none;grid-auto-flow:column;grid-auto-columns:calc(25% - 12px);overflow:hidden}' +
    '.mfs .card{background:var(--p-card-bg,var(--p-surface));border:1px solid var(--p-card-border,var(--p-border));box-shadow:var(--p-card-shadow,none);border-radius:var(--p-card-r,10px);overflow:hidden}' +
    '.mfs .im{aspect-ratio:var(--p-tile-ratio,1);background:var(--p-tile-bg,#fff);color:var(--p-tile-ink,#6b7280);display:grid;place-items:center;position:relative}.mfs .im img{width:100%;height:100%;object-fit:contain;padding:14px}.mfs .im .ph{font-size:11px}' +
    '.mfs .bd{position:absolute;top:9px;left:9px;display:flex;flex-direction:column;gap:5px;align-items:flex-start}.mfs .bd span{font-size:10.5px;font-weight:700;padding:3px 8px;border-radius:99px;background:var(--p-accent);color:var(--p-accent-text)}.mfs .bd span.so{background:#17181a;color:#f1eee8}' +
    '.mfs .tx{padding:12px 14px 15px}.mfs .br{font-size:11px;color:var(--p-muted);text-transform:uppercase;letter-spacing:.05em}.mfs .nm{font-size:14px;font-weight:600;margin:3px 0 7px;line-height:1.3}.mfs .pr{font-weight:700;font-size:15.5px}.mfs .wh{font-size:12px;color:var(--p-muted);margin-top:3px}' +
    '.mfs .sorry{border:1px dashed var(--p-border);border-radius:10px;padding:26px;text-align:center;color:var(--p-muted);font-size:14px}';
  function fill(f, html, mini) {
    var d = f.contentDocument; if (!d) return;
    d.open(); d.write('<!doctype html><html><head><meta charset="utf-8">' + siteHead() + '<style>' + SITE_CSS + 'html,body{margin:0;background:var(--p-bg)}</style></head><body>' + html + '</body></html>'); d.close();
    if (!mini) setTimeout(function () { try { f.style.height = d.documentElement.scrollHeight + 'px'; } catch (e) {} }, 120);
  }
  function sectionHtml(s) {
    var list = shown(s);
    return '<div class="mfs"><div class="in"><div class="hdr"><div>' + (s.eyebrow ? '<div class="eb">' + esc(s.eyebrow) + '</div>' : '') + '<h2>' + esc(s.heading) + '</h2>' + (s.body ? '<p class="sb">' + esc(s.body) + '</p>' : '') + '</div>' + (s.btnLabel ? '<a>' + esc(s.btnLabel) + ' →</a>' : '') + '</div>' +
      (list.length ? '<div class="grid' + (s.layout === 'row' ? ' row' : '') + '">' + list.map(function (p) { return card(p, s); }).join('') + '</div>' : '<div class="sorry">No items match right now. Shoppers don\'t see this section until some do.</div>') + '</div></div>';
  }
  function seg(k, opts) { return '<div class="pb2-seg">' + opts.map(function (o) { return '<button type="button" class="pb2-seg-btn' + (String(ch[k]) === String(o[0]) ? ' active' : '') + '" data-sg="' + k + '|' + o[0] + '">' + o[1] + '</button>'; }).join('') + '</div>'; }
  function renderWords() {
    var s = ch;
    $('mfx-wf').innerHTML = '<div class="pb2-group"><div class="pb2-group-title">Text</div>' +
      '<div class="pb2-field"><label class="pb2-field-label">Eyebrow</label><input class="pb2-input" data-w="eyebrow" value="' + esc(s.eyebrow) + '" placeholder="Optional kicker"></div>' +
      '<div class="pb2-field"><label class="pb2-field-label">Heading</label><input class="pb2-input" data-w="heading" value="' + esc(s.heading) + '"></div>' +
      '<div class="pb2-field"><label class="pb2-field-label">Body</label><textarea class="pb2-input" rows="3" data-w="body">' + esc(s.body) + '</textarea></div></div>' +
      '<div class="pb2-group"><div class="pb2-group-title">Layout</div><div class="pb2-field"><label class="pb2-field-label">How many items</label>' + seg('count', [[4, '4'], [8, '8'], [12, '12']]) + '</div>' +
      '<div class="pb2-field"><label class="pb2-field-label">Arrange as</label>' + seg('layout', [['grid', 'Grid'], ['row', 'One row']]) + '</div></div>' +
      '<div class="pb2-group"><div class="pb2-group-title">On each item</div><div class="pb2-field"><label class="pb2-field-label">Badges <span class="pb2-field-hint">“Selling fast”, “Few left”, “Back in stock”, “New”</span></label>' + seg('badges', [[true, 'Show'], [false, 'Hide']]) + '</div>' +
      '<div class="pb2-field"><label class="pb2-field-label">Prices</label>' + seg('prices', [[true, 'Show'], [false, 'Hide']]) + '</div>' +
      '<div class="pb2-field"><label class="pb2-field-label">Items you don\'t carry</label>' + seg('special', [['show', 'Show as special order'], ['hide', 'Hide']]) + '<div class="pb2-field-hint">Special orders use your usual special order wait and wording.</div></div></div>' +
      '<div class="pb2-group"><div class="pb2-group-title">Button</div><div style="display:flex;gap:8px"><div class="pb2-field" style="flex:1"><label class="pb2-field-label">Label</label><input class="pb2-input" data-w="btnLabel" value="' + esc(s.btnLabel) + '" placeholder="Leave empty to hide"></div><div class="pb2-field" style="flex:1"><label class="pb2-field-label">Link</label><input class="pb2-input" data-w="btnUrl" value="' + esc(s.btnUrl) + '"></div></div></div>';
    fill($('mfx-pv'), sectionHtml(ch));
  }
  function renderSt() {
    var d = dirty();
    $('mfx-st').textContent = d ? 'Unsaved changes' : 'No changes';
    $('mfx-save').disabled = !d;
    $('mfx-tabc').textContent = loading ? '' : shown(ch).length;
  }
  function render() {
    document.querySelectorAll('#mfx-list .pb2-seg-btn').forEach(function (b) { b.classList.toggle('active', b.dataset.l === ch.list); });
    document.querySelectorAll('#mfx-mode .pb2-seg-btn').forEach(function (b) { b.classList.toggle('active', b.dataset.m === ch.mode); });
    renderF(); renderL(); renderOn(); renderSt();
    if (!$('mfx-words').hidden) fill($('mfx-pv'), sectionHtml(ch));
  }
  function tab(t) {
    document.querySelectorAll('.mfx-tab').forEach(function (b) { b.classList.toggle('on', b.dataset.mfxTab === t); });
    $('mfx-items').hidden = t !== 'items'; $('mfx-words').hidden = t !== 'words';
    if (t === 'words') renderWords();
  }

  // ------------------------------------------------------------ events (delegated, so a reloaded inspector keeps working)
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (t.closest('[data-mf-open]')) { e.preventDefault(); open(); return; }
    if (!$('pb2-mf').contains(t)) return;
    if (t.closest('[data-mfx-close]')) { close(); return; }
    var x;
    if ((x = t.closest('[data-mfx-tab]'))) { tab(x.dataset.mfxTab); return; }
    if ((x = t.closest('#mfx-list [data-l]'))) { ch.list = x.dataset.l; sortK = null; load(ch.list); return; }
    if ((x = t.closest('#mfx-mode [data-m]'))) { ch.mode = x.dataset.m; if (ch.mode === 'hand' && !ch.picked.length) ch.picked = shown(Object.assign({}, ch, { mode: 'auto' })).map(function (p) { return p.key; }); render(); return; }
    if ((x = t.closest('[data-tw]'))) { e.preventDefault(); openCat[x.dataset.tw] = !openCat[x.dataset.tw]; renderF(); return; }
    if ((x = t.closest('[data-clear]'))) { ch[x.dataset.clear] = []; render(); return; }
    if ((x = t.closest('[data-fx]'))) { var k = x.dataset.fx; if (k === 'carry') ch.carry = 'all'; if (k === 'cats') { ch.cats = []; ch.brands = []; } if (k === 'price') { ch.min = ''; ch.max = ''; } if (k === 'q') $('mfx-q').value = ''; if (k === 'list') { ch.list = 'fast'; load('fast'); return; } render(); return; }
    if ((x = t.closest('[data-sort]'))) { sortK = x.dataset.sort; renderL(); return; }
    if ((x = t.closest('[data-un]'))) { ch.picked = ch.picked.filter(function (k) { return k !== x.dataset.un; }); render(); return; }
    if ((x = t.closest('[data-sg]'))) { var kv = x.dataset.sg.split('|'), v = kv[1] === 'true' ? true : kv[1] === 'false' ? false : (kv[0] === 'count' ? +kv[1] : kv[1]); ch[kv[0]] = v; renderWords(); renderOn(); renderSt(); return; }
    if ((x = t.closest('[data-add]'))) {
      var p = byKey(x.dataset.add); if (!p) return; x.disabled = true;
      var fd = new FormData(); fd.append('_token', csrf()); fd.append('code', p.code0); fd.append('cid', p.cids[0]); fd.append('key', p.key);
      fetch(EP.add, { method: 'POST', body: fd, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
        .then(function (r) { return r.json().then(function (d) { return [r.ok, d]; }); })
        .then(function (a) { if (!a[0] || !a[1].ok) throw new Error(a[1].error || 'failed'); p.you = a[1].you; p.price = p.you.pr; toast('Added to your inventory as a catalog item'); render(); })
        .catch(function (err) { x.disabled = false; toast(err.message && err.message !== 'failed' ? err.message : 'That item could not be added.', true); });
      return;
    }
    if ((x = t.closest('[data-row]')) && !t.matches('input,button,a')) {
      var key = x.dataset.row; openRow = openRow === key ? null : key; renderL();
      var q = byKey(key);
      if (openRow && q && !weeks[key]) {
        var u = new URL(EP.detail, location.origin); q.cids.forEach(function (c) { u.searchParams.append('cids[]', c); });
        fetch(u, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) { weeks[key] = d.weeks || []; if (openRow === key) renderL(); }).catch(function () { weeks[key] = []; });
      }
    }
  });
  document.addEventListener('change', function (e) {
    var t = e.target; if (!$('pb2-mf').contains(t)) return;
    if (t.dataset.carry) { ch.carry = t.dataset.carry; render(); }
    else if (t.dataset.vend !== undefined) { var cur = vendOn(ch).slice(); cur = t.checked ? cur.concat(t.dataset.vend) : cur.filter(function (c) { return c !== t.dataset.vend; }); ch.vend = cur.length === VEND.length ? [] : cur; if (!cur.length) { ch.vend = []; t.checked = true; toast('Keep at least one vendor.', true); } render(); }
    else if (t.dataset.cat !== undefined) { var v = t.dataset.cat; ch.cats = t.checked ? ch.cats.concat(v) : ch.cats.filter(function (c) { return c !== v && !(v.indexOf(' › ') < 0 && c.indexOf(v + ' › ') === 0); }); render(); }
    else if (t.dataset.brand !== undefined) { var b = t.dataset.brand; ch.brands = t.checked ? ch.brands.concat(b) : ch.brands.filter(function (c) { return c !== b; }); render(); }
    else if (t.dataset.pk) { var k = t.dataset.pk; ch.picked = t.checked ? ch.picked.concat(k) : ch.picked.filter(function (x) { return x !== k; }); render(); }
    else if (t.hasAttribute('data-min')) { ch.min = t.value.trim(); render(); }
    else if (t.hasAttribute('data-max')) { ch.max = t.value.trim(); render(); }
    else if (t.id === 'mfx-count') { ch.count = +t.value; render(); }
  });
  document.addEventListener('input', function (e) {
    var t = e.target; if (!$('pb2-mf').contains(t)) return;
    if (t.id === 'mfx-q') { renderL(); return; }
    if (t.dataset.w) { ch[t.dataset.w] = t.value; fill($('mfx-pv'), sectionHtml(ch)); renderSt(); }
  });
  // drag to reorder your own items in the right-hand list
  var dragKey = null;
  document.addEventListener('dragstart', function (e) { var x = e.target.closest && e.target.closest('#mfx-on [data-drag]'); if (!x) return; dragKey = x.dataset.drag; x.classList.add('drag'); e.dataTransfer.effectAllowed = 'move'; });
  document.addEventListener('dragover', function (e) { if (dragKey && e.target.closest && e.target.closest('#mfx-on')) e.preventDefault(); });
  document.addEventListener('drop', function (e) {
    var x = dragKey && e.target.closest && e.target.closest('#mfx-on [data-drag]'); if (!x) { dragKey = null; return; }
    e.preventDefault(); var to = x.dataset.drag, a = ch.picked.slice(), from = a.indexOf(dragKey), at = a.indexOf(to);
    if (from >= 0 && at >= 0 && from !== at) { a.splice(from, 1); a.splice(at, 0, dragKey); ch.picked = a; }
    dragKey = null; render();
  });
  document.addEventListener('dragend', function () { dragKey = null; document.querySelectorAll('#mfx-on .drag').forEach(function (n) { n.classList.remove('drag'); }); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && $('pb2-mf').classList.contains('on')) close(); });

  $('mfx-save').addEventListener('click', function () {
    if (!dirty()) return;
    writeFields(ch);
    if (typeof window.pb2MarkDirty === 'function') window.pb2MarkDirty();
    var done = function () { orig = JSON.parse(JSON.stringify(ch)); close(true); };
    if (typeof window.pb2SaveNow === 'function') {
      $('mfx-save').disabled = true;
      window.pb2SaveNow().then(function (ok) { if (ok === false) { $('mfx-save').disabled = false; toast('The section could not be saved. Try again.', true); return; } done(); toast('Moving fast saved'); });
    } else done();
  });

  // clicking the section on the canvas opens it too
  function hookCanvas() {
    try {
      var d = $('pb2-preview').contentDocument;
      d.querySelectorAll('[data-pb-type="market_movers"]').forEach(function (el) { if (el.__mf) return; el.__mf = 1; el.addEventListener('dblclick', function () {
        var sid = el.getAttribute('data-pb-section'), it = document.querySelector('.pb2-section-item[data-section-id="' + sid + '"]');
        if (it && !it.classList.contains('selected')) it.click();
        var tries = 0, wait = function () { var f = field('mf_list'), sel = document.querySelector('.pb2-section-item.selected'); if (f && sel && sel.dataset.sectionId === sid) open(); else if (++tries < 30) setTimeout(wait, 100); }; wait();
      }); });
    } catch (e) {}
  }
  var pv = $('pb2-preview'); if (pv) pv.addEventListener('load', function () { HEAD = ''; hookCanvas(); });
})();
</script>
