@extends('layouts.tenant.app')
@php $pageTitle = 'Templates'; @endphp

{{-- MARKER-PATCH-261 — website template gallery. --}}

@section('content')

<div class="ia-page-head">
  <div class="ia-page-head-left">
    <h1 class="ia-page-title">Website Templates</h1>
    <p class="ia-page-subtitle">Five pre-styled, bike-shop-tuned designs. Pick one — your pages and content flow straight in.</p>
  </div>
  <div class="ia-page-actions">
    @if($hasPrev)
      <form method="POST" action="{{ route('tenant.templates.revert') }}" onsubmit="return confirm('Revert to your previous design ({{ $prevName }})? This is a single-level undo.')">
        @csrf
        <button type="submit" class="ia-btn ia-btn--ghost">↩ Back to previous</button>
      </form>
    @endif
    <a href="{{ route('tenant.pages.index') }}" class="ia-btn ia-btn--secondary">Open builder</a>
  </div>
</div>

@if(session('flash'))
  <div class="ia-card" style="padding:12px 16px;margin-bottom:16px;border-left:3px solid var(--ia-accent,#BEF264);font-size:13px">{{ session('flash') }}</div>
@endif
@if(session('flash_error'))
  <div class="ia-card" style="padding:12px 16px;margin-bottom:16px;border-left:3px solid #E0573E;font-size:13px">{{ session('flash_error') }}</div>
@endif

<div class="ia-note" style="margin-bottom:22px;display:flex;gap:8px;align-items:flex-start;font-size:12.5px;opacity:.75">
  <span>ⓘ</span><span>Switching templates restyles your existing pages — it never deletes content. Each look ships with matching mobile layouts.</span>
</div>

{{-- MARKER-CUSTOMIZER — live customizer. The preview is the SAME _thumb
     partial the cards use, so what you tune here is what a template promises.
     It repaints by setting --t-* on the preview root; nothing is saved until
     Save is pressed. --}}
@php
  $czTokens   = \App\Support\DesignTokens::resolve($currentTenant);
  $czTemplate = $currentTenant->site_template
      ? (\App\Support\SiteTemplate::tokens($currentTenant->site_template) ?? [])
      : [];
  $czLayout = $currentTenant->site_template
      ? (\App\Support\SiteTemplate::find($currentTenant->site_template)['layout'] ?? [])
      : ($templates[array_key_first($templates)]['layout'] ?? []);
  $czFonts = ['Inter','Poppins','DM Sans','Nunito','Lato','Raleway','Montserrat','Playfair Display','Merriweather'];
@endphp

{{-- MARKER-CZFIX — without this a validation failure was completely silent --}}
@if($errors->any())
  <div class="ia-flash ia-flash--error" style="margin-bottom:14px">
    Couldn't save your changes: {{ $errors->first() }}
  </div>
@endif
@if(session('flash'))
  <div class="ia-flash ia-flash--success" style="margin-bottom:14px">{{ session('flash') }}</div>
@endif
@if(session('flash_error'))
  <div class="ia-flash ia-flash--error" style="margin-bottom:14px">{{ session('flash_error') }}</div>
@endif

<div class="cz-wrap">
  <div class="cz-stage">
    <div class="cz-stage-bar">
      <span class="cz-stage-title">Live preview</span>
      <span class="cz-dirty" id="cz-dirty">Unsaved changes</span>
    </div>
    <div class="cz-canvas">
      @include('tenant.templates._thumb', ['tokens' => $czTokens, 'layout' => $czLayout])
    </div>
  </div>

  <form method="POST" action="{{ route('tenant.templates.customize') }}" class="cz-panel" id="cz-form">
    @csrf
    <div class="cz-panel-head"><span class="cz-panel-title">Customize</span>
      @if($currentTenant->site_template)
        <span class="cz-base">based on {{ \App\Support\SiteTemplate::name($currentTenant->site_template) }}</span>
      @endif
    </div>

    <div class="cz-scroll">
      @php
        $czGroups = [
          'Color'   => [['accent','Accent','color'],['text','Text','color'],['bg','Page background','color'],['surface','Cards & panels','color'],['muted','Secondary text','color']],
          'Hero'    => [['hero_bg','Hero background','color'],['hero_text','Hero text','color']],
          'Type'    => [['font_heading','Heading font','font'],['font_body','Body font','font'],['heading_weight','Heading weight','range'],['heading_transform','Heading case','case']],
          'Buttons' => [['button_style','Button style','style'],['button_radius','Button corners','range']],
        ];
      @endphp

      @foreach($czGroups as $czGroupName => $czFields)
        <details class="cz-group" {{ $loop->first ? 'open' : '' }}>
          <summary>{{ $czGroupName }}<span class="cz-gdot"></span></summary>
          <div class="cz-body">
            @foreach($czFields as [$czKey, $czLabel, $czType])
              @php
                $czVal = $czTokens[$czKey] ?? '';
                /* MARKER-CZFIX — with no template applied there is no
                   template default, and reset had nothing to reset TO. The
                   pipeline fallback is the honest answer. */
                $czDef = $czTemplate[$czKey] ?? (\App\Support\DesignTokens::FALLBACKS[$czKey] ?? null);
              @endphp
              <div class="cz-row" data-k="{{ $czKey }}" data-default="{{ $czDef }}">
                <label class="cz-lbl">{{ $czLabel }}
                  <button type="button" class="cz-reset" title="Back to the template default">reset</button>
                </label>
                <div class="cz-ctl">
                  @if($czType === 'color')
                    <input type="text" class="cz-hex" value="{{ $czVal }}" data-role="hex" autocomplete="off">
                    <input type="color" class="cz-sw" value="{{ \App\Support\DesignTokens::toHex($czVal, \App\Support\DesignTokens::toHex($czTokens['bg'] ?? '#ffffff')) }}" data-role="pick"> {{-- MARKER-CZFIX --}}
                  @elseif($czType === 'font')
                    <select class="cz-select" data-role="val">
                      @foreach($czFonts as $czFont)
                        <option value="{{ $czFont }}" @selected($czVal === $czFont)>{{ $czFont }}</option>
                      @endforeach
                    </select>
                  @elseif($czType === 'range')
                    @php $czMin = $czKey === 'heading_weight' ? 300 : 0; $czMax = $czKey === 'heading_weight' ? 900 : 24; $czStep = $czKey === 'heading_weight' ? 100 : 1; @endphp
                    <input type="range" class="cz-range" min="{{ $czMin }}" max="{{ $czMax }}" step="{{ $czStep }}" value="{{ (int) $czVal }}" data-role="val">
                    <span class="cz-num">{{ (int) $czVal }}</span>
                  @elseif($czType === 'case')
                    <select class="cz-select" data-role="val">
                      <option value="none" @selected($czVal === 'none')>Normal</option>
                      <option value="uppercase" @selected($czVal === 'uppercase')>UPPERCASE</option>
                    </select>
                  @else
                    <select class="cz-select" data-role="val">
                      <option value="solid" @selected($czVal === 'solid')>Solid</option>
                      <option value="outline" @selected($czVal === 'outline')>Outline</option>
                      <option value="ghost" @selected($czVal === 'ghost')>Ghost</option>
                    </select>
                  @endif
                </div>
                <input type="hidden" name="{{ $czKey }}" value="{{ $czVal }}" data-role="field">
              </div>
            @endforeach
          </div>
        </details>
      @endforeach
    </div>

    <div class="cz-foot">
      <button type="button" class="ia-btn ia-btn--ghost" id="cz-reset-all">Reset all</button>
      <button type="submit" class="ia-btn ia-btn--primary">Save</button>
    </div>
  </form>
</div>

<div class="tpl-section-head">Start from a template</div>

<div class="tpl-grid">
  @foreach($templates as $key => $tpl)
    @php $isCurrent = $key === $current; @endphp
    <div class="tpl-card">
      <div class="tpl-preview" onclick="tplPreview('{{ $key }}')">
        <div class="tpl-preview-inner">
          @include('tenant.templates._thumb', ['tokens' => $tpl['tokens'], 'layout' => $tpl['layout']])
        </div>
        @if($isCurrent)<div class="tpl-badge">● Current</div>@endif
      </div>
      <div class="tpl-card-body">
        <div class="tpl-card-title">{{ $tpl['name'] }}</div>
        <div class="tpl-card-desc">{{ $tpl['desc'] }}</div>
        <div class="tpl-card-tags">
          @foreach($tpl['tags'] as $tag)<span class="tpl-tag">{{ $tag }}</span>@endforeach
        </div>
        <div class="tpl-card-actions">
          <button class="ia-btn ia-btn--secondary ia-btn--sm" style="flex:1" onclick="tplPreview('{{ $key }}')">Preview</button>
          @if($isCurrent)
            <a class="ia-btn ia-btn--secondary ia-btn--sm" style="flex:1;text-align:center" href="{{ route('tenant.pages.index') }}">Edit site</a>
          @else
            <button class="ia-btn ia-btn--primary ia-btn--sm" style="flex:1" onclick="tplConfirm('{{ $key }}','{{ $tpl['name'] }}')">Use this</button>
          @endif
        </div>
      </div>
    </div>
  @endforeach
</div>

{{-- PREVIEW MODAL --}}
<div class="tpl-modal" id="tpl-preview-modal" onclick="if(event.target===this)tplClose('tpl-preview-modal')">
  <div class="tpl-modal-panel tpl-modal-panel--wide">
    <div class="tpl-modal-head">
      <div><strong id="tpl-preview-name"></strong> <span style="opacity:.5;font-size:12px">— preview</span></div>
      <button class="tpl-modal-x" onclick="tplClose('tpl-preview-modal')">×</button>
    </div>
    <div class="tpl-modal-body" id="tpl-preview-body"></div>
    <div class="tpl-modal-foot" id="tpl-preview-foot"></div>
  </div>
</div>

{{-- CONFIRM MODAL --}}
<div class="tpl-modal" id="tpl-confirm-modal" onclick="if(event.target===this)tplClose('tpl-confirm-modal')">
  <div class="tpl-modal-panel">
    <div class="tpl-modal-head">
      <strong>Apply this template?</strong>
      <button class="tpl-modal-x" onclick="tplClose('tpl-confirm-modal')">×</button>
    </div>
    <form method="POST" id="tpl-confirm-form" action="">
      @csrf
      <div class="tpl-modal-body" style="padding:18px 20px;font-size:13.5px;line-height:1.6">
        Switching to <strong id="tpl-confirm-name"></strong> restyles your <strong>published</strong> public site right away — colours, fonts and button styling. By default your pages and content stay exactly as they are.
        <label class="tpl-seed-opt">
          <input type="checkbox" name="seed_layout" value="1">
          <span><strong>Also rebuild my homepage with this template’s layout.</strong> Replaces your current homepage sections with this template’s structure. Other pages and customer data are untouched.</span>
        </label>
      </div>
      <div class="tpl-modal-foot">
        <button type="button" class="ia-btn ia-btn--ghost" onclick="tplClose('tpl-confirm-modal')">Cancel</button>
        <button type="submit" class="ia-btn ia-btn--primary">Apply template</button>
      </div>
    </form>
  </div>
</div>

@php
  $previews = [];
  foreach ($templates as $k => $tpl) {
      $previews[$k] = ['name' => $tpl['name'], 'html' => view('tenant.templates._thumb', ['tokens' => $tpl['tokens'], 'layout' => $tpl['layout']])->render()];
  }
@endphp
<script>
  window.__tplPreviews = @json($previews);
  window.__tplApplyBase = "{{ url('/admin/website/templates') }}";

  function tplPreview(key) {
    var p = window.__tplPreviews[key]; if (!p) return;
    document.getElementById('tpl-preview-name').textContent = p.name;
    document.getElementById('tpl-preview-body').innerHTML = '<div class="tpl-preview-inner tpl-preview-inner--lg">' + p.html + '</div>';
    var foot = document.getElementById('tpl-preview-foot');
    if (key === @json($current)) {
      foot.innerHTML = '<span style="opacity:.5;font-size:12px;align-self:center">This is your current template</span>';
    } else {
      foot.innerHTML = '<button class="ia-btn ia-btn--primary" onclick="tplClose(\'tpl-preview-modal\');tplConfirm(\'' + key + '\',\'' + p.name.replace(/'/g, "\\'") + '\')">Use this</button>';
    }
    document.getElementById('tpl-preview-modal').classList.add('open');
  }
  function tplConfirm(key, name) {
    document.getElementById('tpl-confirm-name').textContent = name;
    document.getElementById('tpl-confirm-form').action = window.__tplApplyBase + '/' + key + '/apply';
    document.getElementById('tpl-confirm-modal').classList.add('open');
  }
  function tplClose(id) { document.getElementById(id).classList.remove('open'); }
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { tplClose('tpl-preview-modal'); tplClose('tpl-confirm-modal'); }
  });
</script>

<style>
  .tpl-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(290px,1fr)); gap:18px; }
  .tpl-card { border-radius:12px; overflow:hidden; box-shadow:inset 0 0 0 .5px var(--ia-border); background:var(--ia-surface,#fff); transition:box-shadow .15s; }
  .tpl-card:hover { box-shadow:0 6px 24px rgba(0,0,0,.12), inset 0 0 0 .5px var(--ia-border); }
  .tpl-preview { position:relative; aspect-ratio:16/11; overflow:hidden; cursor:pointer; border-bottom:.5px solid var(--ia-border); background:#fff; }
  .tpl-preview-inner { transform:scale(.5); transform-origin:top left; width:200%; height:200%; pointer-events:none; }
  .tpl-preview-inner--lg { transform:scale(.8); width:125%; height:auto; }
  .tpl-badge { position:absolute; top:10px; right:10px; background:rgba(0,0,0,.72); color:#fff; font-size:10px; font-weight:700; padding:3px 8px; border-radius:99px; }
  .tpl-card-body { padding:14px 16px 16px; }
  .tpl-card-title { font-size:15px; font-weight:700; }
  .tpl-card-desc { font-size:12.5px; opacity:.6; margin-top:4px; line-height:1.5; }
  .tpl-card-tags { display:flex; flex-wrap:wrap; gap:6px; margin-top:11px; }
  .tpl-tag { font-size:10.5px; padding:2px 8px; border-radius:99px; box-shadow:inset 0 0 0 .5px var(--ia-border); opacity:.7; }
  .tpl-card-actions { display:flex; gap:8px; margin-top:14px; }

  /* mini-site render (consumed by _thumb) */
  .fs { width:560px; font-size:11px; line-height:1.4; }
  .fs-nav { display:flex; align-items:center; gap:14px; padding:12px 18px; font-size:11px; }
  .fs-logo { font-size:14px; margin-right:auto; }
  .fs-btn { padding:6px 12px; font-size:10px; font-weight:700; }
  .fs-hero { padding:34px 18px 30px; }
  .fs-eyebrow { font-size:10px; letter-spacing:.14em; text-transform:uppercase; font-weight:700; margin-bottom:8px; }
  .fs-hero h1 { font-size:30px; line-height:1.05; margin:0 0 8px; }
  .fs-hero p { font-size:12px; max-width:320px; margin:0 0 14px; }
  .fs-sec { padding:20px 18px 26px; }
  .fs-sec-h { font-size:18px; margin-bottom:12px; }
  .fs-cards { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }
  .fs-cards > div { aspect-ratio:4/3; border-radius:8px; }

  /* MARKER-PATCH-263 — blueprint block shapes */
  .fs-hero--split { display:flex; gap:18px; align-items:center; }
  .fs-hero--split .fs-hero-copy { flex:1; }
  .fs-hero-img { flex:1; align-self:stretch; min-height:120px; border-radius:10px; }
  .fs-hero--centered { text-align:center; }
  .fs-hero--centered p { margin-left:auto; margin-right:auto; }
  .fs-hero--compact { padding-top:24px; padding-bottom:22px; }
  .fs-hero--compact h1 { font-size:24px; }
  .fs-cta { padding:26px 18px; text-align:center; }
  .fs-cta .fs-sec-h { margin-bottom:12px; }
  .fs-ti { display:flex; gap:16px; align-items:center; padding:20px 18px; }
  .fs-ti-copy { flex:1; }
  .fs-ti-img { flex:1; align-self:stretch; min-height:90px; border-radius:10px; }
  .fs-gallery { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; padding:16px 18px; }
  .fs-gallery > div { aspect-ratio:1; border-radius:8px; }
  .fs-quote { margin:16px 18px; padding:18px; border-radius:10px; text-align:center; font-size:13px; font-style:italic; }
  .fs-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; padding:18px; }
  .fs-stat { text-align:center; }
  .fs-stat-n { font-size:22px; line-height:1.1; }
  .fs-stat-l { font-size:10px; }
  .fs-steps { display:flex; gap:18px; }
  .fs-step { flex:1; display:flex; align-items:center; gap:8px; }
  .fs-step span { width:22px; height:22px; border-radius:99px; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; flex:none; }
  .fs-step i { height:4px; flex:1; border-radius:2px; }
  .fs-list { display:flex; flex-direction:column; }
  .fs-list-row { display:flex; align-items:center; gap:10px; padding:9px 0; }
  .fs-list-row span { width:18px; height:18px; border-radius:5px; flex:none; }
  .fs-list-row b { height:9px; border-radius:3px; flex:1; max-width:60%; }
  .fs-faq { display:flex; flex-direction:column; }
  .fs-faq-row { padding:11px 0; }
  .fs-faq-row span { display:block; height:9px; width:70%; border-radius:3px; }
  .fs-contact { display:flex; flex-direction:column; gap:8px; max-width:280px; }
  .fs-input { height:26px; border-radius:6px; }
  .fs-footer { padding:16px 18px; font-size:11px; }

  /* modals */
  .tpl-modal { display:none; position:fixed; inset:0; z-index:9500; background:rgba(0,0,0,.55); align-items:center; justify-content:center; padding:24px; }
  .tpl-modal.open { display:flex; }
  .tpl-modal-panel { width:440px; max-width:100%; background:var(--ia-surface,#fff); border-radius:14px; box-shadow:0 20px 60px rgba(0,0,0,.4), inset 0 0 0 .5px var(--ia-border); overflow:hidden; }
  .tpl-modal-panel--wide { width:720px; }
  .tpl-modal-head { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; border-bottom:.5px solid var(--ia-border); font-size:14px; }
  .tpl-modal-x { background:none; border:none; font-size:22px; line-height:1; cursor:pointer; color:inherit; opacity:.5; }
  .tpl-modal-x:hover { opacity:1; }
  .tpl-modal-body { max-height:62vh; overflow:auto; }
  .tpl-modal-panel--wide .tpl-modal-body { padding:18px; background:#0000000a; }
  .tpl-modal-foot { display:flex; justify-content:flex-end; gap:10px; padding:14px 18px; border-top:.5px solid var(--ia-border); }
  .tpl-seed-opt { display:flex; gap:9px; align-items:flex-start; margin-top:14px; padding:12px; border-radius:9px; box-shadow:inset 0 0 0 .5px var(--ia-border); cursor:pointer; font-size:12px; line-height:1.5; }
  .tpl-seed-opt input { margin-top:2px; flex:none; }
  .tpl-seed-opt span { opacity:.85; }

/* MARKER-CUSTOMIZER */
.cz-wrap{display:grid;grid-template-columns:1fr 330px;gap:18px;align-items:start;margin-bottom:30px}
@media(max-width:1000px){.cz-wrap{grid-template-columns:1fr}}
.cz-stage{background:var(--ia-surface);border-radius:var(--ia-r-lg);box-shadow:inset 0 0 0 .5px var(--ia-border);overflow:hidden}
.cz-stage-bar{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:.5px solid var(--ia-border)}
.cz-stage-title{font-size:12px;opacity:.55}
.cz-dirty{margin-left:auto;font-size:11.5px;color:var(--ia-accent);opacity:0;transition:opacity .12s}
.cz-dirty.on{opacity:1}
.cz-canvas{padding:18px;background:rgba(0,0,0,.28);display:flex;justify-content:center;overflow:hidden}
.cz-canvas .fs{width:100%;max-width:600px}
.cz-panel{background:var(--ia-surface);border-radius:var(--ia-r-lg);box-shadow:inset 0 0 0 .5px var(--ia-border);overflow:hidden;display:flex;flex-direction:column}
.cz-panel-head{display:flex;align-items:center;gap:8px;padding:13px 16px;border-bottom:.5px solid var(--ia-border)}
.cz-panel-title{font-size:13px;font-weight:500;text-transform:uppercase;letter-spacing:.06em}
.cz-base{margin-left:auto;font-size:11px;opacity:.5}
.cz-scroll{max-height:60vh;overflow-y:auto}
.cz-group{border-bottom:.5px solid var(--ia-border)}
.cz-group summary{padding:11px 16px;font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;opacity:.55;cursor:pointer;list-style:none;display:flex;align-items:center;gap:7px}
.cz-group summary::-webkit-details-marker{display:none}
.cz-group summary::before{content:'\25B8';font-size:9px;opacity:.6}
.cz-group[open] summary::before{content:'\25BE'}
.cz-gdot{margin-left:auto;width:6px;height:6px;border-radius:50%;background:var(--ia-accent);opacity:0}
.cz-group.is-edited .cz-gdot{opacity:1}
.cz-body{padding:4px 16px 14px}
.cz-row{display:flex;align-items:center;gap:10px;padding:7px 0;min-height:36px}
.cz-lbl{font-size:12.5px;flex:1;min-width:0;display:flex;align-items:center;gap:6px}
.cz-reset{border:0;background:none;color:var(--ia-accent);font:inherit;font-size:10.5px;cursor:pointer;opacity:0;padding:0;text-decoration:underline}
.cz-row.is-edited .cz-reset{opacity:.85}
.cz-ctl{flex:0 0 auto;display:flex;align-items:center;gap:8px}
.cz-hex{width:80px;font-family:ui-monospace,monospace;font-size:11.5px;padding:5px 7px;border-radius:6px;border:.5px solid var(--ia-border);background:var(--ia-input-bg);color:var(--ia-text)}
.cz-sw{width:30px;height:26px;padding:0;border:.5px solid var(--ia-border-strong);border-radius:6px;background:none;cursor:pointer}
.cz-select{font-size:12px;padding:5px 8px;border-radius:6px;border:.5px solid var(--ia-border);background:var(--ia-input-bg);color:var(--ia-text);font-family:inherit}
.cz-range{width:100px;accent-color:var(--ia-accent)}
.cz-num{font-family:ui-monospace,monospace;font-size:11.5px;opacity:.6;width:30px;text-align:right}
.cz-foot{display:flex;gap:8px;justify-content:flex-end;padding:13px 16px;border-top:.5px solid var(--ia-border)}
.tpl-section-head{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.07em;opacity:.45;margin:0 0 12px}
</style>

@endsection

{{-- MARKER-CUSTOMIZER --}}
<script>
(function () {
  var form = document.getElementById('cz-form');
  var prev = document.querySelector('.cz-canvas [data-fs-preview]');
  if (!form || !prev) { return; }

  var dirty = document.getElementById('cz-dirty');

  // token key -> the CSS variable the preview paints from
  var VAR = {
    accent: '--t-accent', text: '--t-text', bg: '--t-bg', surface: '--t-surface',
    muted: '--t-muted', hero_bg: '--t-hero-bg', hero_text: '--t-hero-text',
    heading_weight: '--t-h-weight', heading_transform: '--t-h-case',
    button_radius: '--t-btn-r'
  };

  function contrast(hex) {
    var c = (hex || '').replace('#', '');
    if (c.length !== 6) { return '#111111'; }
    var r = parseInt(c.substr(0, 2), 16), g = parseInt(c.substr(2, 2), 16), b = parseInt(c.substr(4, 2), 16);
    return (0.299 * r + 0.587 * g + 0.114 * b) > 150 ? '#111111' : '#ffffff';
  }

  function paint(key, value) {
    if (key === 'font_heading') { prev.style.setProperty('--t-f-head', "'" + value + "', sans-serif"); return; }
    if (key === 'font_body')    { prev.style.setProperty('--t-f-body', "'" + value + "', sans-serif"); return; }
    if (key === 'button_style') { return; } // needs a re-render; saved, not previewed
    if (key === 'button_radius'){ prev.style.setProperty(VAR[key], value + 'px'); return; }
    if (!VAR[key]) { return; }
    prev.style.setProperty(VAR[key], value);
    if (key === 'accent') { prev.style.setProperty('--t-accent-text', contrast(value)); }
  }

  function mark(row) {
    var field = row.querySelector('[data-role="field"]');
    var def   = row.getAttribute('data-default');
    var edited = def !== null && def !== '' && String(field.value) !== String(def);
    row.classList.toggle('is-edited', edited);

    var group = row.closest('.cz-group');
    if (group) {
      group.classList.toggle('is-edited', !!group.querySelector('.cz-row.is-edited'));
    }
    if (dirty) { dirty.classList.add('on'); }
  }

  function setRow(row, value, silent) {
    var key   = row.getAttribute('data-k');
    var field = row.querySelector('[data-role="field"]');
    field.value = value;

    var hex = row.querySelector('[data-role="hex"]');
    var pick = row.querySelector('[data-role="pick"]');
    var val = row.querySelector('[data-role="val"]');
    var num = row.querySelector('.cz-num');

    if (hex) { hex.value = value; }
    if (pick && /^#[0-9a-fA-F]{6}$/.test(value)) { pick.value = value; }
    if (val) { val.value = value; }
    if (num) { num.textContent = value; }

    paint(key, value);
    if (!silent) { mark(row); }
  }

  document.querySelectorAll('.cz-row').forEach(function (row) {
    var hex  = row.querySelector('[data-role="hex"]');
    var pick = row.querySelector('[data-role="pick"]');
    var val  = row.querySelector('[data-role="val"]');

    if (hex)  { hex.addEventListener('input', function () {
      if (/^#[0-9a-fA-F]{6}$/.test(hex.value)) { setRow(row, hex.value); }
    }); }
    if (pick) { pick.addEventListener('input', function () { setRow(row, pick.value); }); }
    if (val)  { val.addEventListener('input', function () { setRow(row, val.value); }); }

    row.querySelector('.cz-reset').addEventListener('click', function () {
      var def = row.getAttribute('data-default');
      if (def) { setRow(row, def); } // MARKER-CZFIX
    });

    mark(row);
  });

  if (dirty) { dirty.classList.remove('on'); }

  document.getElementById('cz-reset-all').addEventListener('click', function () {
    document.querySelectorAll('.cz-row').forEach(function (row) {
      var def = row.getAttribute('data-default');
      if (def) { setRow(row, def); } // MARKER-CZFIX
    });
  });
})();
</script>
