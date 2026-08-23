#!/usr/bin/env python3
"""Collapsible sidebar.

220px of chrome is a lot when you're working in the page builder, where
the canvas and the inspector are both fighting for width. This collapses
the sidebar to a 60px icon rail and back.

Details that matter:
  * The choice is remembered in localStorage and applied to <html> in a
    blocking inline script BEFORE first paint — otherwise every page load
    flashes the expanded sidebar for a frame, which is worse than not
    having the feature.
  * Nav labels aren't display:none'd — they'd be lost to screen readers.
    They're clipped, and each item gains a title so hovering still names
    it.
  * The toggle is hidden below 900px, where the sidebar is already a
    horizontal strip and collapsing means nothing.
  * `[` toggles it, ignored while typing in a field.
Run from repo root: python3 apply-sidebar-collapse.py
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

LAYOUT  = 'resources/views/layouts/tenant/app.blade.php'
SIDEBAR = 'resources/views/layouts/tenant/_sidebar.blade.php'
NAV     = 'resources/views/layouts/tenant/_nav-items.blade.php'
CSS     = 'public/css/tenant/base.css'

# ============================================================
# 1) Pre-paint state — must run before the shell renders
# ============================================================
sub(LAYOUT,
    """<div class="ia-shell">""",
    """{{-- MARKER-SIDEBAR-COLLAPSE — applied to <html> before first paint. A
     deferred script would let the expanded sidebar flash on every load. --}}
<script>
  try {
    if (localStorage.getItem('ia-sidebar-collapsed') === '1') {
      document.documentElement.classList.add('ia-sb-collapsed');
    }
  } catch (e) {}
</script>

<div class="ia-shell">""",
    "layout: pre-paint class")

# ============================================================
# 2) Toggle button in the logo row
# ============================================================
sub(SIDEBAR,
    """  {{-- Logo (image only when uploaded, fallback to letter + name when not) --}}
  <div class="ia-sidebar-logo">""",
    """  {{-- MARKER-SIDEBAR-COLLAPSE --}}
  <button type="button" class="ia-sb-collapse-btn" id="ia-sb-collapse"
          aria-label="Collapse sidebar" aria-expanded="true" title="Collapse sidebar  [">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <polyline points="15 18 9 12 15 6"/>
    </svg>
  </button>

  {{-- Logo (image only when uploaded, fallback to letter + name when not) --}}
  <div class="ia-sidebar-logo">""",
    "sidebar: toggle button")

# ============================================================
# 3) Nav items get a title so the rail is still navigable
# ============================================================
sub(NAV,
    """  <a href="{{ $url }}" class="ia-nav-item {{ $isActive ? 'active' : '' }}">
    {!! $item['icon'] !!}
    {{ $item['label'] }}
  </a>""",
    """  {{-- MARKER-SIDEBAR-COLLAPSE — title carries the label when collapsed; the
       text itself stays in the DOM for screen readers rather than being
       display:none'd away. --}}
  <a href="{{ $url }}" class="ia-nav-item {{ $isActive ? 'active' : '' }}" title="{{ $item['label'] }}">
    {!! $item['icon'] !!}
    <span class="ia-nav-label">{{ $item['label'] }}</span>
  </a>""",
    "nav: label span + title")

# ============================================================
# 4) Styles
# ============================================================
css = read(CSS)
if 'MARKER-SIDEBAR-COLLAPSE' in css:
    print("SKIP (already applied): base.css styles")
else:
    write(CSS, css + """

/* MARKER-SIDEBAR-COLLAPSE ---------------------------------------------------
   220px of chrome is expensive in the page builder. Collapse to an icon rail.
   Everything keys off a class on <html> so the state applies before paint. */
.ia-sb-collapse-btn {
  position: absolute; top: 14px; right: 8px; z-index: 3;
  display: flex; align-items: center; justify-content: center;
  width: 22px; height: 22px; padding: 0;
  background: none; border: none; cursor: pointer;
  border-radius: 5px;
  color: var(--ia-text-dim, rgba(255,255,255,.5));
  opacity: 0; transition: opacity .13s, background .13s, color .13s;
}
.ia-sidebar:hover .ia-sb-collapse-btn,
.ia-sb-collapse-btn:focus-visible { opacity: 1; }
.ia-sb-collapse-btn:hover {
  background: rgba(127,127,127,.14);
  color: var(--ia-text, #f0f0f0);
}
.ia-sidebar { position: relative; }

html.ia-sb-collapsed .ia-shell { grid-template-columns: 60px 1fr; }
html.ia-sb-collapsed .ia-sb-collapse-btn { opacity: 1; right: 50%; transform: translateX(50%); }
html.ia-sb-collapsed .ia-sb-collapse-btn svg { transform: rotate(180deg); }

/* Labels are clipped rather than removed, so assistive tech still reads them. */
html.ia-sb-collapsed .ia-nav-label,
html.ia-sb-collapsed .ia-sidebar-logo-name,
html.ia-sb-collapsed .ia-sb-user-text,
html.ia-sb-collapsed .ia-nav-section,
html.ia-sb-collapsed .ia-sidebar-bottom,
html.ia-sb-collapsed .ia-sb-imp-badge {
  position: absolute !important;
  width: 1px; height: 1px;
  overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap;
}
html.ia-sb-collapsed .ia-sidebar-logo { justify-content: center; padding-top: 44px; }
html.ia-sb-collapsed .ia-sidebar-logo img { max-width: 34px; }
html.ia-sb-collapsed .ia-nav-item {
  justify-content: center;
  padding-left: 0; padding-right: 0;
}
html.ia-sb-collapsed .ia-nav-item svg { opacity: .8; }
html.ia-sb-collapsed .ia-sidebar-identity { padding-left: 0; padding-right: 0; }
html.ia-sb-collapsed .ia-sb-user-row { justify-content: center; padding-left: 0; padding-right: 0; }
html.ia-sb-collapsed .ia-sidebar-divider { margin-left: 10px; margin-right: 10px; }

/* The account menu is absolutely positioned; float it clear of the rail. */
html.ia-sb-collapsed .ia-sb-user-menu { left: 62px; right: auto; min-width: 210px; }

/* Below 900px the sidebar is already a horizontal strip — nothing to collapse. */
@media (max-width: 900px) {
  .ia-sb-collapse-btn { display: none; }
  html.ia-sb-collapsed .ia-shell { grid-template-columns: 1fr; }
  html.ia-sb-collapsed .ia-nav-label,
  html.ia-sb-collapsed .ia-sidebar-logo-name,
  html.ia-sb-collapsed .ia-sb-user-text,
  html.ia-sb-collapsed .ia-nav-section,
  html.ia-sb-collapsed .ia-sidebar-bottom {
    position: static !important;
    width: auto; height: auto; clip: auto; overflow: visible;
  }
}
""")
    print("OK: base.css styles")

# ============================================================
# 5) Behaviour
# ============================================================
JS = 'public/js/tenant/sidebar-collapse.js'
import os
if os.path.exists(JS):
    print("SKIP (exists): sidebar-collapse.js")
else:
    write(JS, """/* MARKER-SIDEBAR-COLLAPSE — toggle + persistence.
   The pre-paint class is set inline in the layout; this only handles the
   click, the keyboard shortcut, and writing the choice back. */
(function () {
  'use strict';

  var btn = document.getElementById('ia-sb-collapse');
  if (!btn) return;

  var KEY = 'ia-sidebar-collapsed';
  var root = document.documentElement;

  function sync() {
    var collapsed = root.classList.contains('ia-sb-collapsed');
    btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    btn.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    btn.setAttribute('title', (collapsed ? 'Expand sidebar' : 'Collapse sidebar') + '  [');
  }

  function toggle() {
    var collapsed = root.classList.toggle('ia-sb-collapsed');
    try { localStorage.setItem(KEY, collapsed ? '1' : '0'); } catch (e) {}
    sync();
    // Anything measuring its own width (the builder canvas, charts) needs a
    // nudge — the grid column changed without the window resizing.
    window.dispatchEvent(new Event('resize'));
  }

  btn.addEventListener('click', toggle);

  document.addEventListener('keydown', function (e) {
    if (e.key !== '[' || e.metaKey || e.ctrlKey || e.altKey) return;
    var t = e.target;
    if (t && (t.matches('input, textarea, select, [contenteditable]') || t.isContentEditable)) return;
    e.preventDefault();
    toggle();
  });

  sync();
})();
""")
    print("OK: sidebar-collapse.js")

sub(LAYOUT,
    """<script src="{{ asset('js/tenant/mobile-nav.js') }}?v={{ filemtime(public_path('js/tenant/mobile-nav.js')) }}" defer></script>""",
    """<script src="{{ asset('js/tenant/sidebar-collapse.js') }}?v={{ filemtime(public_path('js/tenant/sidebar-collapse.js')) }}" defer></script>
<script src="{{ asset('js/tenant/mobile-nav.js') }}?v={{ filemtime(public_path('js/tenant/mobile-nav.js')) }}" defer></script>""",
    "layout: script tag")

print("\\nDone. No migration needed. view:clear after deploy.")
