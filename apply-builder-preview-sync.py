#!/usr/bin/env python3
"""Page builder: connect the section list and the live preview.

Right now the two panes ignore each other. Clicking a section loads its
editor but the preview stays wherever it was scrolled, so on a long page
you're editing something you can't see. And the preview is inert — you
can see the section you want but there's no way to get to it except
guessing which row in the list it is.

This wires both directions:
  * Click a section in the list  → preview scrolls to it and flashes an
    outline, so you know which one you landed on.
  * Hover a section in the preview → it highlights, and clicking it
    selects that section in the builder.

Hovering alone deliberately does NOT swap the editor pane: the cursor
crosses three or four sections on its way anywhere, and each swap costs
a fetch and throws away whatever field you were mid-edit in. Hover
highlights; click commits.

Mechanics: the preview is a same-origin authenticated route, so the
parent can reach into the iframe directly — but it's still driven by
postMessage so this keeps working if the preview ever moves to the
public domain.
Run from repo root: python3 apply-builder-preview-sync.py
"""
import sys

def read(p):
    with open(p) as f: return f.read()
def write(p, s):
    with open(p, 'w') as f: f.write(s)
def sub(p, old, new, label):
    s = read(p)
    if new == '':
        if old not in s:
            print(f"SKIP (already applied): {label}"); return
    elif new in s:
        print(f"SKIP (already applied): {label}"); return
    if old not in s:
        print(f"FAIL: anchor not found for {label} in {p}"); sys.exit(1)
    write(p, s.replace(old, new, 1))
    print(f"OK: {label}")

CTRL   = 'app/Http/Controllers/Tenant/PageBuilderController.php'
PUBLIC = 'resources/views/public/layout.blade.php'
EDIT   = 'resources/views/tenant/pages/edit.blade.php'

# ============================================================
# 1) Preview render knows it's in the builder
# ============================================================
sub(CTRL,
    """        return view('public.page', compact(
            'page', 'sections', 'navItems', 'catalog',
            'splashPage', 'splashSections', 'splashCfg'
        ));
    }

    public function store(Request $request)""",
    """        // MARKER-BUILDER-SYNC — only the builder preview gets the section
        // anchors and the click-to-select bridge; the public page stays clean.
        $builderPreview = true;

        return view('public.page', compact(
            'page', 'sections', 'navItems', 'catalog',
            'splashPage', 'splashSections', 'splashCfg', 'builderPreview'
        ));
    }

    public function store(Request $request)""",
    "controller: builderPreview flag")

# ============================================================
# 2) Public layout — wrap sections so they can be found and clicked
# ============================================================
sub(PUBLIC,
    """      @include($partial, [
        'c'        => $sc,
        'section'  => $section,
        'navItems' => $navItems,
        'catalog'  => $catalog,
        'tenant'   => $currentTenant,
      ])""",
    """      {{-- MARKER-BUILDER-SYNC — an addressable wrapper, builder-only. Section
           partials render their own <section> elements with markup we don't
           control, so a wrapper is the only reliable anchor. --}}
      @if(!empty($builderPreview))<div data-pb-section="{{ $section->id }}" data-pb-type="{{ $section->section_type }}">@endif
      @include($partial, [
        'c'        => $sc,
        'section'  => $section,
        'navItems' => $navItems,
        'catalog'  => $catalog,
        'tenant'   => $currentTenant,
      ])
      @if(!empty($builderPreview))</div>@endif""",
    "public: section wrappers")

sub(PUBLIC,
    """@if(!empty($splashPage) && !empty($splashSections) && count($splashSections))""",
    """{{-- MARKER-BUILDER-SYNC — hover highlight + click-to-select, and the
     scroll-to handler the builder calls. Builder preview only. --}}
@if(!empty($builderPreview))
<style>
  [data-pb-section] { position: relative; }
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
  [data-pb-section] { cursor: pointer; }
</style>
<script>
(function () {
  var wraps = Array.prototype.slice.call(document.querySelectorAll('[data-pb-section]'));
  if (!wraps.length) return;

  function post(msg) {
    try { parent.postMessage(msg, window.location.origin); } catch (e) {}
  }

  wraps.forEach(function (w) {
    w.addEventListener('mouseenter', function () {
      wraps.forEach(function (o) { o.classList.remove('pb-hover'); });
      w.classList.add('pb-hover');
    });
    w.addEventListener('mouseleave', function () { w.classList.remove('pb-hover'); });

    // Click selects the section in the builder. Links and form controls keep
    // their own behaviour — the preview still has to be usable as a page.
    w.addEventListener('click', function (e) {
      if (e.target.closest('a, button, input, select, textarea, label')) return;
      e.preventDefault();
      post({ source: 'pb-preview', type: 'select', id: w.dataset.pbSection, sectionType: w.dataset.pbType });
    }, true);
  });

  // Builder asks us to scroll to a section.
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
})();
</script>
@endif

@if(!empty($splashPage) && !empty($splashSections) && count($splashSections))""",
    "public: hover + click bridge")

# ============================================================
# 3) Builder — scroll on select, and act on preview clicks
# ============================================================
sub(EDIT,
    """  document.querySelectorAll('.pb2-section-item').forEach((el, idx) => {
    el.addEventListener('click', e => {
      if (e.target.closest('.pb2-drag-handle')) return;
      const sid  = el.dataset.sectionId;
      const type = el.dataset.sectionType;
      if (!sid) return;
      selectSection(sid, type, idx + 1);
    });
  });""",
    """  document.querySelectorAll('.pb2-section-item').forEach((el, idx) => {
    el.addEventListener('click', e => {
      if (e.target.closest('.pb2-drag-handle')) return;
      const sid  = el.dataset.sectionId;
      const type = el.dataset.sectionType;
      if (!sid) return;
      selectSection(sid, type, idx + 1);
      scrollPreviewTo(sid);
    });
  });

  // ─── MARKER-BUILDER-SYNC — keep the two panes pointed at the same thing ──
  // postMessage rather than reaching into the iframe directly: the preview is
  // same-origin today, but this keeps working if it ever isn't.
  function scrollPreviewTo(sectionId) {
    const frame = document.getElementById('pb2-preview');
    if (!frame || !frame.contentWindow) return;
    frame.contentWindow.postMessage(
      { source: 'pb-builder', type: 'scrollTo', id: sectionId },
      window.location.origin
    );
  }

  window.addEventListener('message', (e) => {
    if (e.origin !== window.location.origin) return;
    const d = e.data || {};
    if (d.source !== 'pb-preview' || d.type !== 'select' || !d.id) return;

    const item = document.querySelector(`.pb2-section-item[data-section-id="${d.id}"]`);
    if (!item) return;

    // Index as shown in the list, so the inspector subtitle stays truthful.
    const all = Array.prototype.slice.call(document.querySelectorAll('.pb2-section-item'));
    selectSection(d.id, item.dataset.sectionType || d.sectionType, all.indexOf(item) + 1);

    // The list scrolls independently and the chosen row is often out of view.
    item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  });""",
    "editor: sync handlers")

print("\\nDone. No migration needed. view:clear after deploy.")
