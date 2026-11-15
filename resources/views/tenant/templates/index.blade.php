@extends('layouts.tenant.app')
@php $pageTitle = 'Templates'; @endphp

{{-- MARKER-WEB-TEMPLATES: Website › Templates. Templates come from master admin; the shop picks
     one, light or dark, and changes any piece. The preview frame is the shop's real site
     drawn with the unsaved choices (only this signed-in session sees it). --}}

@section('content')
<div class="ia-page-head"><div class="ia-page-head-left"><h1 class="ia-page-title">Templates</h1>
  <div class="ia-page-subtitle">How your public site looks. Pick a template, preview it on your real pages, then change any piece.</div></div></div>

<details class="tp-legend"><summary>What a template changes, and what it doesn't</summary><div class="lg-body">
  <div><b>Changes:</b> colours, fonts, the menu bar, the top of your pages, cards, shop photos, buttons and the footer, everywhere on your public site: pages, shop, search, booking and customer account pages.</div>
  <div><b>Doesn't change:</b> your pages, their words and photos, your products, or your logo. A colour or layout you set on one section in the page builder keeps winning over the template.</div>
  <div><b>Nothing is live until you press Apply to site.</b> The preview here is only shown to you.</div>
</div></details>

@if($templates->isEmpty())
  <div class="tp-empty">No templates are available on your plan yet.</div>
@else
<div class="tp-wrap">
  <div class="tp-side">
    <div class="tp-h">Template</div>
    <div class="tp-list" id="tpList"></div>

    <div class="tp-h">Light or dark</div>
    <div class="tp-mode"><span class="tp-seg" id="tpMode"><button type="button" data-m="dark">Dark</button><button type="button" data-m="light">Light</button><button type="button" data-m="auto">Match visitor's device</button></span>
      <div class="d" id="tpModeNote"></div>
      <div class="tp-see" id="tpSeeRow" hidden><span>Editing and previewing</span><span class="tp-seg" id="tpSee"><button type="button" data-s="dark">Dark</button><button type="button" data-s="light">Light</button></span></div></div>

    <div class="tp-h">Change a piece <span class="tp-pill g" id="tpChg" hidden></span></div>
    <div class="tp-grp" id="tpGroups"></div>

    <div class="tp-save"><span class="st" id="tpSt">No unsaved changes</span>
      <button type="button" class="ia-btn ia-btn--ghost ia-btn--sm" id="tpDiscard" disabled>Discard</button>
      <button type="button" class="ia-btn ia-btn--primary ia-btn--sm" id="tpApply" disabled>Apply to site</button></div>
  </div>

  <div class="tp-pv">
    <div class="tp-pvbar"><span class="tp-seg" id="tpPage"><button type="button" data-p="home" class="on">Homepage</button><button type="button" data-p="shop">Shop</button><button type="button" data-p="search">Search</button></span>
      <span class="tp-seg" id="tpDev"><button type="button" data-d="desk" class="on">Desktop</button><button type="button" data-d="phone">Phone</button></span>
      <span class="sp"></span><span class="lb" id="tpPvLabel"></span></div>
    <div class="tp-frame" id="tpFrame"><div class="tp-loading" id="tpLoading">Updating preview…</div><iframe id="tpIframe" title="Preview of your site" src="about:blank"></iframe></div>
    <div class="tp-note">Your real pages and products. Links inside the preview keep showing your unsaved changes.</div>
  </div>
</div>

<form method="POST" action="{{ route('tenant.templates.apply') }}" id="tpForm" hidden>@csrf</form>
@endif
@endsection

@push('styles')
<style>
.tp-legend{border:.5px solid var(--ia-border);border-radius:10px;padding:12px 16px;background:var(--ia-surface);margin-bottom:22px}
.tp-legend summary{cursor:pointer;font-size:12.5px;font-weight:500;color:var(--ia-text-muted)}
.tp-legend .lg-body{margin-top:10px;display:flex;flex-direction:column;gap:8px;font-size:12.5px;line-height:1.6;color:var(--ia-text-muted)}
.tp-legend b{color:var(--ia-text);font-weight:500}
.tp-empty{padding:30px 0;color:var(--ia-text-muted);font-size:13.5px}
.tp-wrap{display:grid;grid-template-columns:350px minmax(0,1fr);gap:28px;align-items:start}
.tp-h{font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:var(--ia-text-muted);font-weight:600;margin:0 0 8px;display:flex;align-items:center;gap:8px}
.tp-list{border-top:.5px solid var(--ia-border);margin-bottom:22px}
.tp-row{display:grid;grid-template-columns:18px 1fr;gap:10px;padding:12px 4px;border-bottom:.5px solid var(--ia-border);cursor:pointer}
.tp-row:hover{background:var(--ia-hover)}.tp-row.on{background:var(--ia-surface-2)}
.tp-row .rd{width:16px;height:16px;border-radius:50%;border:1.5px solid var(--ia-border-strong);margin-top:2px;display:grid;place-items:center}
.tp-row.on .rd{border-color:var(--ia-accent)}.tp-row.on .rd:after{content:'';width:8px;height:8px;border-radius:50%;background:var(--ia-accent)}
.tp-row .nm{font-size:14px;font-weight:500;display:flex;align-items:center;gap:8px}
.tp-row .ds{font-size:12px;color:var(--ia-text-muted);line-height:1.5;margin-top:2px}
.tp-row .sws{display:flex;gap:8px;margin-top:8px}
.tp-row .sw{display:flex;height:14px;border-radius:4px;overflow:hidden;width:120px;border:.5px solid var(--ia-border)}.tp-row .sw i{flex:1}
.tp-pill{font-size:10px;font-weight:600;padding:1px 7px;border-radius:99px;background:var(--ia-accent);color:var(--ia-accent-text)}
.tp-pill.g{background:var(--ia-surface-3);color:var(--ia-text-muted)}
.tp-seg{display:inline-flex;border:.5px solid var(--ia-border-strong);border-radius:8px;overflow:hidden}
.tp-seg button{background:transparent;border:0;padding:5px 11px;font-size:12px;color:var(--ia-text-muted)}
.tp-seg button+button{border-left:.5px solid var(--ia-border)}
.tp-seg button.on{background:var(--ia-surface-3);color:var(--ia-text);font-weight:500}
.tp-mode{display:flex;flex-direction:column;gap:6px;margin:0 0 22px}
.tp-see{display:flex;align-items:center;justify-content:space-between;gap:10px;font-size:12px;color:var(--ia-text-muted);padding-top:4px}
.tp-other{font-size:12px;color:var(--ia-text-muted);line-height:1.5;margin-top:8px}.tp-other b{color:var(--ia-danger, #e5484d);font-weight:500}.tp-mode .d{font-size:12px;color:var(--ia-text-muted);line-height:1.5}
.tp-grp{border-top:.5px solid var(--ia-border)}
.tp-g{border-bottom:.5px solid var(--ia-border)}
.tp-g>button{width:100%;display:flex;align-items:center;gap:8px;background:none;border:0;padding:12px 2px;color:var(--ia-text);font-size:13.5px;font-weight:500;text-align:left}
.tp-g>button .v{margin-left:auto;font-size:12px;color:var(--ia-text-muted);font-weight:400}
.tp-g>button .ch{width:14px;color:var(--ia-text-muted);transition:transform .15s}
.tp-g.open>button .ch{transform:rotate(90deg)}
.tp-g .dot{width:6px;height:6px;border-radius:50%;background:var(--ia-accent);display:none}.tp-g.chg .dot{display:inline-block}
.tp-g .bd{display:none;padding:2px 2px 16px}.tp-g.open .bd{display:block}
.tp-reset{font-size:11.5px;color:var(--ia-text-muted);background:none;border:0;text-decoration:underline;padding:0;margin-top:10px}
.tp-tiles{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.tp-tile{background:none;border:.5px solid var(--ia-border-strong);border-radius:8px;padding:7px;text-align:left;color:var(--ia-text-muted);font-size:11.5px}
.tp-tile svg{display:block;width:100%;height:auto;margin-bottom:5px;border-radius:4px}
.tp-tile.on{border-color:var(--ia-accent);color:var(--ia-text);box-shadow:0 0 0 1px var(--ia-accent)}
.tp-col{display:flex;align-items:center;gap:10px;padding:5px 0;font-size:12.5px}
.tp-col input[type=color]{width:30px;height:24px;border:.5px solid var(--ia-border-strong);border-radius:6px;padding:0;background:none;cursor:pointer}
.tp-col input[type=color]::-webkit-color-swatch-wrapper{padding:2px}.tp-col input[type=color]::-webkit-color-swatch{border:0;border-radius:4px}
.tp-col .hx,.tp-f .hx{margin-left:auto;font-family:var(--ia-font-mono,monospace);font-size:11.5px;color:var(--ia-text-muted)}
.tp-col .k{flex:1}.tp-col .k small{display:block;font-size:11px;color:var(--ia-text-muted)}
.tp-read{margin-top:12px;padding-top:10px;border-top:.5px dashed var(--ia-border)}
.tp-read .r{display:flex;align-items:center;gap:8px;font-size:12px;padding:3px 0}
.tp-read .sc{margin-left:auto;font-variant-numeric:tabular-nums;color:var(--ia-text-muted)}
.tp-read .ok,.tp-read .bad{font-size:10px;font-weight:700;padding:1px 6px;border-radius:99px}
.tp-read .ok{background:rgba(122,168,42,.18);color:#9bc65a}.tp-read .bad{background:rgba(226,87,76,.18);color:#f08a80}
.tp-read .fix{font-size:11px;background:none;border:.5px solid var(--ia-border-strong);border-radius:6px;color:var(--ia-text);padding:1px 7px}
.tp-f{display:flex;flex-direction:column;gap:10px}
.tp-f label{font-size:12px;color:var(--ia-text-muted);display:flex;justify-content:space-between;align-items:center;gap:10px}
.tp-f .ia-select{width:170px}.tp-f input[type=range]{width:150px;accent-color:var(--ia-accent)}
.tp-note2{font-size:12px;color:var(--ia-text-muted);line-height:1.5;margin-top:8px}
.tp-save{position:sticky;bottom:0;background:var(--ia-bg);border-top:.5px solid var(--ia-border);padding:12px 0;margin-top:6px;display:flex;gap:8px;align-items:center;z-index:5}
.tp-save .st{font-size:12px;color:var(--ia-text-muted);flex:1}.tp-save .st.dirty{color:#e8b25a}
.tp-pv{position:sticky;top:16px}
.tp-pvbar{display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap}
.tp-pvbar .lb{font-size:12px;color:var(--ia-text-muted)}.tp-pvbar .sp{flex:1}
.tp-frame{position:relative;border:.5px solid var(--ia-border-strong);border-radius:12px;overflow:hidden;background:#000;margin:0 auto;transition:width .2s;height:calc(100vh - 150px);min-height:560px}
.tp-frame.phone{width:390px}
.tp-frame iframe{width:100%;height:100%;border:0;display:block;background:#fff}
.tp-loading{position:absolute;left:50%;top:12px;transform:translateX(-50%);z-index:2;font-size:12px;background:rgba(0,0,0,.75);color:#fff;padding:5px 12px;border-radius:99px;display:none}
.tp-loading.on{display:block}
.tp-note{font-size:11.5px;color:var(--ia-text-muted);margin-top:8px;line-height:1.5}
.ia-btn:disabled{opacity:.4;cursor:default}
@media(max-width:1100px){.tp-wrap{grid-template-columns:1fr}.tp-pv{position:static}}
</style>
@endpush

@push('scripts')
@if($templates->isNotEmpty())
<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var TPL = {};
  {!! json_encode($templates, JSON_HEX_TAG | JSON_HEX_AMP) !!}.forEach(function (t) { TPL[t.key] = t; });
  var LIVE = {!! json_encode($live, JSON_HEX_TAG | JSON_HEX_AMP) !!};
  var ROLES = {!! json_encode(\App\Support\SiteLook::ROLES, JSON_HEX_TAG) !!};
  var PAIRS = {!! json_encode(\App\Support\SiteLook::PAIRS, JSON_HEX_TAG) !!};
  var PIECES = {!! json_encode(\App\Support\SiteLook::PIECES, JSON_HEX_TAG | JSON_FORCE_OBJECT) !!};
  var FONTS = {!! json_encode(\App\Support\SiteLook::FONTS, JSON_HEX_TAG) !!};
  var ON_TEMPLATE = {{ $onTemplate ? 'true' : 'false' }};
  var URLS = { home: '/', shop: '/shop', search: '/shop?q=' + encodeURIComponent({!! json_encode($searchTerm, JSON_HEX_TAG) !!}) };
  var PREVIEW = {!! json_encode(route('tenant.templates.preview')) !!};
  var CSRF = document.querySelector('meta[name=csrf-token]').content;

  function copy(o) { return JSON.parse(JSON.stringify(o)); }
  function obj(x) { return (!x || Array.isArray(x)) ? {} : x; }
  function norm(d) { d.c = obj(d.c); d.c.dark = obj(d.c.dark); d.c.light = obj(d.c.light); d.p = obj(d.p); return d; }
  var live = norm(copy(LIVE)), draft = norm(copy(LIVE));
  var page = 'home', openG = 'colours';
  // which version is being edited and previewed; on Match visitor's device both are shown to someone
  var see = (window.matchMedia && matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
  var em = function () { return draft.mode === 'auto' ? see : draft.mode; };
  var other = function () { return em() === 'dark' ? 'light' : 'dark'; };
  var modeName = function (m) { return m === 'auto' ? 'Match visitor\'s device' : m === 'dark' ? 'Dark' : 'Light'; };

  var cur = function () { return TPL[draft.tpl]; };
  var col = function (k, m) { m = m || em(); return draft.c[m][k] || cur().modes[m][k]; };
  var pc = function (k) { return draft.p[k] !== undefined ? draft.p[k] : cur().pieces[k]; };
  var dirty = function () { return !ON_TEMPLATE || JSON.stringify(draft) !== JSON.stringify(live); };

  // ------------------------------------------------ readability
  function lum(h) { h = h.replace('#', ''); var v = [0, 2, 4].map(function (i) { return parseInt(h.substr(i, 2), 16) / 255; }).map(function (c) { return c <= .03928 ? c / 12.92 : Math.pow((c + .055) / 1.055, 2.4); }); return .2126 * v[0] + .7152 * v[1] + .0722 * v[2]; }
  function ratio(a, b) { var x = lum(a), y = lum(b); return (Math.max(x, y) + .05) / (Math.min(x, y) + .05); }
  function nudge(fg, bg, need) {
    var toW = lum(bg) < .2, h = fg.replace('#', ''), rgb = [0, 2, 4].map(function (i) { return parseInt(h.substr(i, 2), 16); });
    var hex = function () { return '#' + rgb.map(function (v) { return Math.max(0, Math.min(255, v)).toString(16).padStart(2, '0'); }).join(''); };
    for (var s = 0; s < 60 && ratio(hex(), bg) < need; s++) rgb = rgb.map(function (v) { return Math.round(toW ? v + (255 - v) * .08 : v * .92); });
    return hex();
  }
  function bad(m) { return PAIRS.filter(function (p) { return ratio(col(p[0], m), col(p[1], m)) < p[3]; }); }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  // ------------------------------------------------ template list + light/dark
  function sw(t, m) { var c = TPL[t].modes[m]; return ['bg', 's1', 'tx', 'mu', 'ac'].map(function (k) { return '<i style="background:' + c[k] + '"></i>'; }).join(''); }
  function renderList() {
    $('tpList').innerHTML = Object.keys(TPL).map(function (k) { var t = TPL[k];
      return '<div class="tp-row ' + (draft.tpl === k ? 'on' : '') + '" data-t="' + k + '"><span class="rd"></span><div><div class="nm">' + esc(t.name) + (ON_TEMPLATE && live.tpl === k ? '<span class="tp-pill">On your site</span>' : '') + '</div><div class="ds">' + esc(t.description || '') + '</div><div class="sws"><span class="sw" title="Dark">' + sw(k, 'dark') + '</span><span class="sw" title="Light">' + sw(k, 'light') + '</span></div></div></div>';
    }).join('');
    document.querySelectorAll('.tp-row').forEach(function (r) { r.onclick = function () {
      if (r.dataset.t === draft.tpl) return;
      var had = Object.keys(draft.p).length + Object.keys(draft.c.dark).length + Object.keys(draft.c.light).length;
      var go = function () { draft.tpl = r.dataset.t; draft.c = { dark: {}, light: {} }; draft.p = {}; all(); };
      if (!had) return go();
      IntakeConfirm.show({ title: 'Switch to ' + TPL[r.dataset.t].name + '?', message: 'The pieces you changed belong to ' + cur().name + '. Switching starts ' + TPL[r.dataset.t].name + ' fresh, without them.', confirmText: 'Switch' }).then(function (ok) { if (ok) go(); });
    }; });
  }
  function renderMode() {
    document.querySelectorAll('#tpMode button').forEach(function (b) { b.classList.toggle('on', b.dataset.m === draft.mode); });
    $('tpModeNote').textContent = draft.mode === 'dark' ? 'Every visitor sees the dark version.' : draft.mode === 'light' ? 'Every visitor sees the light version.'
      : 'Visitors whose phone or computer is set to dark see the dark version; everyone else sees the light one. Booking and emails use the dark colours.';
    $('tpSeeRow').hidden = draft.mode !== 'auto';
    document.querySelectorAll('#tpSee button').forEach(function (b) { b.classList.toggle('on', b.dataset.s === see); });
  }

  // ------------------------------------------------ pieces
  function thumb(g, v) {
    var B = '#2a2a2a', L = '#5a5a5a', A = 'var(--ia-accent)', s = '<rect width="120" height="56" fill="#161616"/>';
    if (g === 'nav') s += (v === 'float' ? '<rect x="8" y="6" width="104" height="11" rx="3" fill="' + B + '" stroke="' + L + '" stroke-width=".6"/><rect x="13" y="10" width="20" height="3" fill="' + L + '"/><rect x="92" y="9" width="15" height="5" rx="1.5" fill="' + A + '"/>' : v === 'solid' ? '<rect width="120" height="15" fill="' + B + '"/><rect x="6" y="6" width="20" height="3" fill="' + L + '"/><rect x="98" y="5" width="15" height="5" rx="1.5" fill="' + A + '"/>' : v === 'center' ? '<rect x="45" y="5" width="30" height="4" fill="' + L + '"/><rect x="30" y="13" width="60" height="2" fill="' + B + '"/><rect y="19" width="120" height=".6" fill="' + L + '"/>' : '<rect width="120" height="56" fill="#3a3530"/><rect x="6" y="6" width="20" height="3" fill="#ddd"/><rect x="98" y="5" width="15" height="5" rx="1.5" fill="' + A + '"/>') + '<rect x="10" y="30" width="50" height="5" fill="' + L + '"/><rect x="10" y="39" width="34" height="3" fill="' + B + '"/>';
    if (g === 'hero') s += v === 'photo' ? '<rect width="120" height="56" fill="#3a3530"/><rect x="10" y="30" width="50" height="6" fill="#eee"/><rect x="10" y="40" width="18" height="6" rx="2" fill="' + A + '"/>' : v === 'split' ? '<rect x="8" y="18" width="44" height="6" fill="' + L + '"/><rect x="8" y="28" width="30" height="3" fill="' + B + '"/><rect x="8" y="36" width="16" height="6" rx="2" fill="' + A + '"/><rect x="62" y="8" width="50" height="40" rx="5" fill="#3a3530"/>' : '<rect x="30" y="16" width="60" height="7" fill="' + L + '"/><rect x="40" y="28" width="40" height="3" fill="' + B + '"/><rect x="51" y="36" width="18" height="6" rx="2" fill="' + A + '"/>';
    if (g === 'cards') for (var i = 0; i < 3; i++) { var x = 6 + i * 38;
      s += v === 'bordered' ? '<rect x="' + x + '" y="10" width="32" height="36" rx="3" fill="none" stroke="' + L + '" stroke-width=".8"/>' : v === 'flat' ? '<rect x="' + x + '" y="10" width="32" height="36" rx="3" fill="' + B + '"/>' : v === 'raised' ? '<rect x="' + (x + 1) + '" y="12" width="32" height="36" rx="5" fill="#000" opacity=".6"/><rect x="' + x + '" y="10" width="32" height="36" rx="5" fill="#2e2e2e"/>' : '<rect x="' + x + '" y="10" width="32" height="36" rx="3" fill="' + B + '"/><rect x="' + x + '" y="10" width="32" height="16" rx="3" fill="#3a3530"/>';
      s += '<rect x="' + (x + 5) + '" y="' + (v === 'image' ? 31 : 18) + '" width="18" height="3" fill="#999"/><rect x="' + (x + 5) + '" y="' + (v === 'image' ? 37 : 25) + '" width="12" height="2.5" fill="' + A + '"/>'; }
    if (g === 'footer') s += v === 'simple' ? '<rect y="40" width="120" height=".6" fill="' + L + '"/><rect x="8" y="46" width="18" height="3" fill="' + L + '"/><rect x="90" y="46" width="22" height="3" fill="' + B + '"/>' : v === 'columns' ? '<rect y="18" width="120" height=".6" fill="' + L + '"/>' + [8, 38, 68, 92].map(function (x) { return '<rect x="' + x + '" y="25" width="16" height="3" fill="' + L + '"/><rect x="' + x + '" y="32" width="12" height="2" fill="' + B + '"/><rect x="' + x + '" y="37" width="14" height="2" fill="' + B + '"/>'; }).join('') : '<rect y="14" width="120" height="30" fill="' + A + '"/><rect x="10" y="25" width="44" height="6" fill="#161616"/><rect x="86" y="24" width="24" height="8" rx="2" fill="#161616"/><rect x="8" y="48" width="18" height="3" fill="' + L + '"/>';
    return '<svg viewBox="0 0 120 56">' + s + '</svg>';
  }
  var label = function (g) { var o = PIECES[g][2]; return o ? (o[pc(g)] || pc(g)) : pc(g); };
  var GROUPS = [
    ['colours', 'Colours', function () { return draft.mode === 'auto' ? 'Dark and light · ' + (see === 'dark' ? 'editing dark' : 'editing light') : modeName(draft.mode); }, []],
    ['fonts', 'Fonts', function () { return pc('fh') + (pc('fh') !== pc('fb') ? ' + ' + pc('fb') : ''); }, ['fh', 'fb', 'hw', 'hc']],
    ['nav', 'Menu bar', function () { return label('nav'); }, ['nav']],
    ['hero', 'Top of the page', function () { return label('hero'); }, ['hero']],
    ['cards', 'Cards', function () { return label('cards'); }, ['cards']],
    ['shop', 'Shop photos', function () { return (pc('tiles') === 'white' ? 'White' : 'Surface') + ' · ' + (pc('ratio') === '1/1' ? 'square' : 'tall') + ' · ' + pc('cols') + ' a row'; }, ['tiles', 'ratio', 'cols']],
    ['shape', 'Corners and buttons', function () { return pc('r') + 'px · ' + (pc('btn') === 'solid' ? 'solid' : 'outline'); }, ['r', 'btn']],
    ['footer', 'Footer', function () { return label('footer'); }, ['footer']]
  ];
  function changed(g) { if (g[0] === 'colours') return draft.mode === 'auto' ? Object.keys(draft.c.dark).length + Object.keys(draft.c.light).length > 0 : Object.keys(draft.c[draft.mode]).length > 0; return g[3].some(function (k) { return draft.p[k] !== undefined; }); }
  function seg(k, opts) { return '<span class="tp-seg">' + opts.map(function (o) { return '<button type="button" data-pv="' + k + '|' + o[0] + '" class="' + (String(pc(k)) === String(o[0]) ? 'on' : '') + '">' + o[1] + '</button>'; }).join('') + '</span>'; }
  function body(g) {
    if (g === 'colours') return Object.keys(ROLES).map(function (k) { return '<label class="tp-col"><input type="color" value="' + col(k) + '" data-c="' + k + '"><span class="k">' + ROLES[k][0] + '<small>' + ROLES[k][1] + '</small></span><span class="hx">' + col(k) + '</span></label>'; }).join('') +
      '<div class="tp-read"><div class="tp-h" style="margin-bottom:4px">Can people read it?</div>' + PAIRS.map(function (p) { var r = ratio(col(p[0]), col(p[1])), ok = r >= p[3];
        return '<div class="r"><span class="' + (ok ? 'ok' : 'bad') + '">' + (ok ? 'Reads well' : 'Hard to read') + '</span>' + p[2] + '<span class="sc">' + r.toFixed(1) + ':1</span>' + (ok ? '' : '<button type="button" class="fix" data-fix="' + p[0] + '|' + p[1] + '|' + p[3] + '">Fix</button>') + '</div>'; }).join('') +
        (draft.mode === 'auto' ? (bad(other()).length ? '<div class="tp-other"><b>The ' + other() + ' version has ' + bad(other()).length + ' hard-to-read pair' + (bad(other()).length > 1 ? 's' : '') + '.</b> Switch Editing and previewing to ' + modeName(other()) + ' to fix ' + (bad(other()).length > 1 ? 'them' : 'it') + '.</div>' : '<div class="tp-other">The ' + other() + ' version reads well too.</div>') : '') + '</div>';
    if (g === 'fonts') return '<div class="tp-f"><label>Headings<select class="ia-select" data-p="fh">' + FONTS.map(function (f) { return '<option ' + (pc('fh') === f ? 'selected' : '') + '>' + f + '</option>'; }).join('') + '</select></label>' +
      '<label>Everything else<select class="ia-select" data-p="fb">' + FONTS.map(function (f) { return '<option ' + (pc('fb') === f ? 'selected' : '') + '>' + f + '</option>'; }).join('') + '</select></label>' +
      '<label>Heading weight' + seg('hw', [[400, 'Light'], [500, 'Regular'], [650, 'Bold'], [800, 'Heavy']]) + '</label><label>Heading letters' + seg('hc', [['none', 'As typed'], ['upper', 'ALL CAPS']]) + '</label></div>';
    if (['nav', 'hero', 'cards', 'footer'].indexOf(g) >= 0) {
      var o = PIECES[g][2];
      return '<div class="tp-tiles">' + Object.keys(o).map(function (v) { return '<button type="button" class="tp-tile ' + (pc(g) === v ? 'on' : '') + '" data-pv="' + g + '|' + v + '">' + thumb(g, v) + o[v] + (cur().pieces[g] === v ? ' <span style="opacity:.6">· template</span>' : '') + '</button>'; }).join('') + '</div>' +
        (g !== 'cards' ? '<div class="tp-note2">Applies to every ' + (g === 'nav' ? 'menu bar' : g === 'hero' ? 'top section' : 'footer') + ' that follows the template. One you changed in the page builder keeps its own layout.</div>' : '<div class="tp-note2">Cards that have their own colours set in the page builder keep them.</div>');
    }
    if (g === 'shop') return '<div class="tp-f"><label>Behind product photos' + seg('tiles', [['white', 'White'], ['surface', 'Surface colour']]) + '</label><div class="tp-note2" style="margin-top:-4px">White suits catalog photos shot on white, which most are.</div>' +
      '<label>Photo shape' + seg('ratio', [['1/1', 'Square'], ['4/5', 'Tall']]) + '</label><label>Products in a row' + seg('cols', [[3, '3'], [4, '4'], [5, '5']]) + '</label></div>';
    if (g === 'shape') return '<div class="tp-f"><label>Corners <span style="display:flex;gap:8px;align-items:center"><input type="range" min="0" max="24" value="' + pc('r') + '" data-range="r"><span class="hx" style="width:34px;text-align:right">' + pc('r') + 'px</span></span></label>' +
      '<label>Main buttons' + seg('btn', [['solid', 'Solid'], ['outline', 'Outline']]) + '</label></div>';
    return '';
  }
  function renderGroups() {
    $('tpGroups').innerHTML = GROUPS.map(function (g) { return '<div class="tp-g ' + (openG === g[0] ? 'open' : '') + ' ' + (changed(g) ? 'chg' : '') + '" data-g="' + g[0] + '"><button type="button"><svg class="ch" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M6 3l5 5-5 5"/></svg>' + g[1] + '<span class="dot" title="Changed from the template"></span><span class="v">' + esc(g[2]()) + '</span></button><div class="bd">' + (openG === g[0] ? body(g[0]) + (changed(g) ? '<button type="button" class="tp-reset" data-reset="' + g[0] + '">Back to ' + esc(cur().name) + '\'s ' + g[1].toLowerCase() + '</button>' : '') : '') + '</div></div>'; }).join('');
    var n = GROUPS.filter(changed).length; $('tpChg').hidden = !n; $('tpChg').textContent = n + ' changed';
    document.querySelectorAll('.tp-g>button').forEach(function (b) { b.onclick = function () { var g = b.parentNode.dataset.g; openG = openG === g ? '' : g; renderGroups(); }; });
    document.querySelectorAll('[data-pv]').forEach(function (b) { b.onclick = function () { var kv = b.dataset.pv.split('|'), v = ['hw', 'cols', 'r'].indexOf(kv[0]) >= 0 ? +kv[1] : kv[1]; setP(kv[0], v); }; });
    document.querySelectorAll('select[data-p]').forEach(function (s) { s.onchange = function () { setP(s.dataset.p, s.value); }; });
    document.querySelectorAll('[data-range]').forEach(function (s) {
      s.oninput = function () { s.nextElementSibling.textContent = s.value + 'px'; };
      s.onchange = function () { setP(s.dataset.range, +s.value); };
    });
    document.querySelectorAll('input[data-c]').forEach(function (i) {
      i.oninput = function () { i.parentNode.querySelector('.hx').textContent = i.value; };
      i.onchange = function () { setC(i.dataset.c, i.value.toLowerCase()); };
    });
    document.querySelectorAll('[data-fix]').forEach(function (b) { b.onclick = function () { var x = b.dataset.fix.split('|'); setC(x[0], nudge(col(x[0]), col(x[1]), +x[2] + .2)); if (window.IntakeToast) IntakeToast.success('Adjusted ' + ROLES[x[0]][0].toLowerCase() + ' so it reads well'); }; });
    document.querySelectorAll('[data-reset]').forEach(function (b) { b.onclick = function () { var g = GROUPS.filter(function (x) { return x[0] === b.dataset.reset; })[0]; if (g[0] === 'colours') { if (draft.mode === 'auto') draft.c = { dark: {}, light: {} }; else draft.c[em()] = {}; } else g[3].forEach(function (k) { delete draft.p[k]; }); all(); }; });
  }
  function setP(k, v) { if (String(cur().pieces[k]) === String(v)) delete draft.p[k]; else draft.p[k] = v; all(); }
  function setC(k, v) { var m = em(); if (cur().modes[m][k] === v) delete draft.c[m][k]; else draft.c[m][k] = v; all(); }

  // ------------------------------------------------ preview: the real site with the unsaved choices
  var pvTimer = null, pvSeq = 0;
  function renderPreview() {
    $('tpPvLabel').textContent = cur().name + ' · ' + (draft.mode === 'auto' ? 'matches device, showing ' + see : draft.mode) + (dirty() ? ' · not applied yet' : '');
    clearTimeout(pvTimer); $('tpLoading').classList.add('on');
    pvTimer = setTimeout(function () {
      var seq = ++pvSeq;
      fetch(PREVIEW, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, body: JSON.stringify(draft) })
        .then(function (r) { if (!r.ok) throw new Error('preview'); return r.json(); }).then(function () {
          if (seq !== pvSeq) return;
          var u = URLS[page]; $('tpIframe').src = u + (u.indexOf('?') >= 0 ? '&' : '?') + 'look_preview=1' + (draft.mode === 'auto' ? '&look_scheme=' + see : '') + '&_=' + Date.now();
        }).catch(function () { $('tpLoading').classList.remove('on'); if (window.IntakeToast) IntakeToast.error('The preview could not update. Try again in a moment.'); });
    }, 350);
  }
  $('tpIframe').addEventListener('load', function () { if ($('tpIframe').getAttribute('src') !== 'about:blank') $('tpLoading').classList.remove('on'); });

  // ------------------------------------------------ save
  function renderSave() {
    var d = dirty();
    $('tpSt').textContent = d ? (ON_TEMPLATE ? 'Unsaved changes. Your site still shows ' + TPL[live.tpl].name + '.' : 'Not applied yet. Your site still shows its current look.') : 'No unsaved changes';
    $('tpSt').classList.toggle('dirty', d); $('tpDiscard').disabled = !d || !ON_TEMPLATE; $('tpApply').disabled = !d;
  }
  $('tpDiscard').onclick = function () { draft = norm(copy(live)); all(); };
  $('tpApply').onclick = function () {
    var probs = (draft.mode === 'auto' ? ['dark', 'light'] : [draft.mode]).reduce(function (a, m) { return a.concat(bad(m).map(function (p) { return p[2].toLowerCase() + (draft.mode === 'auto' ? ' (' + m + ')' : ''); })); }, []);
    IntakeConfirm.show({ title: 'Apply ' + cur().name + ' to your site?', message: 'Customers see it straight away on every public page: your pages, shop, search, booking and account pages. Your pages’ words, photos and products stay as they are.' + (probs.length ? ' Hard to read: ' + probs.join(', ') + '. You can apply anyway, or press Fix first.' : ''), confirmText: 'Apply to site' })
      .then(function (ok) {
        if (!ok) return;
        var f = $('tpForm'), add = function (n, v) { var i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i); };
        add('tpl', draft.tpl); add('mode', draft.mode);
        ['dark', 'light'].forEach(function (m) { Object.keys(draft.c[m]).forEach(function (k) { add('c[' + m + '][' + k + ']', draft.c[m][k]); }); });
        Object.keys(draft.p).forEach(function (k) { add('p[' + k + ']', draft.p[k]); });
        window.__tpSubmitting = true; f.submit();
      });
  };
  window.addEventListener('beforeunload', function (e) { if (dirty() && ON_TEMPLATE && !window.__tpSubmitting) { e.preventDefault(); e.returnValue = ''; } });

  document.querySelectorAll('#tpMode button').forEach(function (b) { b.onclick = function () { draft.mode = b.dataset.m; all(); }; });
  document.querySelectorAll('#tpSee button').forEach(function (b) { b.onclick = function () { see = b.dataset.s; all(); }; });
  document.querySelectorAll('#tpPage button').forEach(function (b) { b.onclick = function () { page = b.dataset.p; document.querySelectorAll('#tpPage button').forEach(function (x) { x.classList.toggle('on', x === b); }); renderPreview(); }; });
  document.querySelectorAll('#tpDev button').forEach(function (b) { b.onclick = function () { document.querySelectorAll('#tpDev button').forEach(function (x) { x.classList.toggle('on', x === b); }); $('tpFrame').classList.toggle('phone', b.dataset.d === 'phone'); }; });

  function all() { renderList(); renderMode(); renderGroups(); renderSave(); renderPreview(); }
  all();
})();
</script>
@endif
@endpush
