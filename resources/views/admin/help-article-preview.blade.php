<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $page->title }} — guide preview</title>
{{-- MARKER-MKT-SECTION-BG — help articles preview here, NOT in the marketing
     template. Same public partials the shop's Help page renders, same design
     tokens, no marketing nav or footer around it. What you see is what a
     qualifying shop sees. --}}
<style>
  *{box-sizing:border-box}
  body{margin:0;background:#0d0f11;color:#e8ebee;
    font:15px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Inter,sans-serif}
  .gp-note{font-size:12px;color:rgba(232,235,238,.5);padding:10px 20px;
    border-bottom:1px solid rgba(255,255,255,.08);letter-spacing:.02em}
  .gp-wrap{max-width:820px;margin:0 auto;padding:28px 20px 80px}
  .gp-wrap h1{font-size:26px;font-weight:800;letter-spacing:-.025em;margin:0 0 18px}
  .hlp-body{
{!! \App\Support\DesignTokens::cssVars(\App\Support\DesignTokens::resolve($tenant), '    ') !!}
  }
  .hlp-body section{padding-left:0 !important;padding-right:0 !important}
  .gp-empty{color:rgba(232,235,238,.5);font-size:14px}

  [data-pb-section] { position: relative; cursor: pointer; }
  [data-pb-section]::after {
    content: ''; position: absolute; inset: 0; z-index: 2147483000;
    pointer-events: none; opacity: 0;
    outline: 2px solid #BEF264; outline-offset: -2px;
    background: rgba(190,242,100,.06);
    transition: opacity .12s;
  }
  [data-pb-section].pb-hover::after,
  [data-pb-section].pb-flash::after { opacity: 1; }
  [data-pb-section].pb-flash::after { transition: opacity .35s; }
</style>
</head>
<body>
<div class="gp-note">Guide preview · rendered the way a shop's Help page shows it</div>

<div class="gp-wrap">
  <h1>{{ $page->title }}</h1>
  <div class="hlp-body">
    @php $dt = \App\Support\DesignTokens::resolve($tenant); @endphp
    @forelse($sections as $section)
      @php
        $type    = $section->section_type;
        $allowed = in_array($type, \App\Http\Controllers\Tenant\HelpController::DOC_SECTIONS, true);
        $partial = 'public.sections._' . $type;
        $sc      = $section->content ?? [];
        $sc['bg_color'] = \App\Support\DesignTokens::sectionBg($sc['bg_color'] ?? null, $type, $dt);
      @endphp
      <div data-pb-section="{{ $section->id }}" data-pb-type="{{ $type }}">
        @if($allowed && view()->exists($partial))
          @include($partial, [
            'c'        => $sc,
            'section'  => $section,
            'navItems' => [],
            'catalog'  => null,
            'tenant'   => $tenant,
          ])
        @else
          <div style="padding:14px 18px;margin:12px 0;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.4);border-radius:8px;color:#fcd34d;font-size:13px;">
            <b>{{ $type }}</b> isn't shown in guides — shops won't see this section. Use text, steps, FAQ, images, stats or a callout instead.
          </div>
        @endif
      </div>
    @empty
      <p class="gp-empty">Add a section to see it here.</p>
    @endforelse
  </div>
</div>

<script>
(function () {
  function boot() {
    var wraps = Array.prototype.slice.call(document.querySelectorAll('[data-pb-section]'));
    if (!wraps.length) return;
    function post(msg) { try { parent.postMessage(msg, window.location.origin); } catch (e) {} }
    wraps.forEach(function (w) {
      w.addEventListener('mouseenter', function () {
        wraps.forEach(function (o) { o.classList.remove('pb-hover'); });
        w.classList.add('pb-hover');
      });
      w.addEventListener('mouseleave', function () { w.classList.remove('pb-hover'); });
      w.addEventListener('click', function (e) {
        if (e.target.closest('a, button, input, select, textarea, label')) return;
        e.preventDefault();
        post({ source: 'pb-preview', type: 'select', id: w.dataset.pbSection, sectionType: w.dataset.pbType });
      }, true);
    });
    window.addEventListener('message', function (e) {
      if (e.origin !== window.location.origin) return;
      var d = e.data || {};
      if (d.source !== 'pb-builder' || d.type !== 'scrollTo') return;
      var el = document.querySelector('[data-pb-section="' + d.id + '"]');
      if (!el) return;
      el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      el.classList.add('pb-flash');
      setTimeout(function () { el.classList.remove('pb-flash'); }, 900);
    });
    post({ source: 'pb-preview', type: 'ready' });
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
</script>
</body>
</html>
