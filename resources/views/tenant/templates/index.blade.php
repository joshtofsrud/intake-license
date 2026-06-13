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
    <div class="tpl-modal-body" style="padding:18px 20px;font-size:13.5px;line-height:1.6">
      Switching to <strong id="tpl-confirm-name"></strong> restyles your <strong>published</strong> public site right away. Your pages and their content stay exactly as they are — only colours, fonts and button styling change. You can step back to your previous design afterwards.
    </div>
    <div class="tpl-modal-foot">
      <button class="ia-btn ia-btn--ghost" onclick="tplClose('tpl-confirm-modal')">Cancel</button>
      <form method="POST" id="tpl-confirm-form" action="">
        @csrf
        <button type="submit" class="ia-btn ia-btn--primary">Apply template</button>
      </form>
    </div>
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
</style>

@endsection
