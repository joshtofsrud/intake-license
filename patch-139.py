#!/usr/bin/env python3
"""
Patch 139 — light-mode rendering + duplicate-chevron fix on the
master admin dashboard.

Two distinct issues:

1. Light mode rendering — patch 135 built the dashboard assuming the
   admin was always dark. Filament respects user theme preference, so
   when the user is in light mode, the literal #f0f0f0 text and
   rgba(255,255,255,.xx) borders render invisible on white. Fix: define
   .pd variables for the light theme as defaults, and override them
   under .dark .pd { ... } for dark theme.

2. Duplicate chevron on the refresh dropdown — Filament injects its
   own chevron via a global rule that applies to all <select> elements.
   The native browser chevron stays, producing two stacked chevrons.
   Fix: explicit `appearance:none` on .pd-refresh-select and remove the
   background-image Filament adds.

Idempotent.
"""

import argparse
import pathlib
import sys

OLD = '''  .pd {
    --pd-bg: var(--gray-950, #0a0a0a);
    --pd-surface: var(--gray-900, #131313);
    --pd-surface-2: var(--gray-800, #1a1a1a);
    --pd-border: rgba(255,255,255,.08);
    --pd-border-strong: rgba(255,255,255,.18);
    --pd-text: #f0f0f0;
    --pd-text-muted: rgba(255,255,255,.62);
    --pd-text-dim: rgba(255,255,255,.42);
    --pd-accent: #BEF264;
    --pd-ok: #86EFAC;
    --pd-warn: #FBBF24;
    --pd-bad: #F87171;
    --pd-info: #7DD3FC;
    --pd-r-md: 6px;
    --pd-r-lg: 10px;
    --pd-font-mono: 'JetBrains Mono', ui-monospace, monospace;

    color: var(--pd-text);
    font-size: 14px;
    line-height: 1.55;
  }'''

NEW = '''  /* MARKER-PATCH-139 — light-default + dark override.
     Filament toggles `.dark` on <html>; we mirror that. Tile of
     surface colors below derived from Filament's stock light/dark
     ramps so the dashboard sits comfortably inside either theme. */
  .pd {
    --pd-bg: #ffffff;
    --pd-surface: #ffffff;
    --pd-surface-2: #f7f7f8;
    --pd-border: rgba(0,0,0,.08);
    --pd-border-strong: rgba(0,0,0,.15);
    --pd-text: #111827;
    --pd-text-muted: rgba(17,24,39,.7);
    --pd-text-dim: rgba(17,24,39,.5);
    --pd-accent: #65A30D;
    --pd-ok: #16A34A;
    --pd-warn: #D97706;
    --pd-bad: #DC2626;
    --pd-info: #0284C7;
    --pd-r-md: 6px;
    --pd-r-lg: 10px;
    --pd-font-mono: 'JetBrains Mono', ui-monospace, monospace;
    color: var(--pd-text);
    font-size: 14px;
    line-height: 1.55;
  }
  .dark .pd {
    --pd-bg: #0a0a0a;
    --pd-surface: #131313;
    --pd-surface-2: #1a1a1a;
    --pd-border: rgba(255,255,255,.08);
    --pd-border-strong: rgba(255,255,255,.18);
    --pd-text: #f0f0f0;
    --pd-text-muted: rgba(255,255,255,.62);
    --pd-text-dim: rgba(255,255,255,.42);
    --pd-accent: #BEF264;
    --pd-ok: #86EFAC;
    --pd-warn: #FBBF24;
    --pd-bad: #F87171;
    --pd-info: #7DD3FC;
  }
  /* Pulse-bar track and any rgba-on-white-only surfaces also need a
     light-aware fallback. They're currently coded as rgba(255,255,255,.06)
     which is invisible on white. Override per-component. */
  .pd .pd-pulse-bar { background: rgba(0,0,0,.06); }
  .dark .pd .pd-pulse-bar { background: rgba(255,255,255,.06); }
  .pd .pd-funnel-bar { background: rgba(0,0,0,.05); }
  .dark .pd .pd-funnel-bar { background: rgba(255,255,255,.04); }
  .pd .pd-ratio-track { background: rgba(0,0,0,.05); }
  .dark .pd .pd-ratio-track { background: rgba(255,255,255,.05); }
  .pd-h-row.ok .pd-h-stripe   { background: var(--pd-ok); opacity:.55; }
  .pd-h-row.warn .pd-h-stripe { background: var(--pd-warn); }
  .pd-h-row.bad .pd-h-stripe  { background: var(--pd-bad); }
  .pd-h-row.idle .pd-h-stripe { background: var(--pd-border-strong); }
  .pd-h-row:hover { background: var(--pd-surface-2); }
  .pd-biz-card:hover, .pd-wp-card:hover, .pd-domain-row:hover, .pd-event-row:hover {
    background: var(--pd-surface-2);
  }

  /* Refresh dropdown — kill Filament-injected chevron + native chevron,
     draw exactly one of our own. */
  .pd-refresh-select {
    -webkit-appearance: none !important;
    -moz-appearance: none !important;
    appearance: none !important;
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'><path d='M1 1l4 4 4-4' stroke='currentColor' stroke-width='1.2' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>") !important;
    background-repeat: no-repeat !important;
    background-position: right 10px center !important;
    background-size: 10px 6px !important;
    padding-right: 28px !important;
  }'''


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('root'); ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    p = pathlib.Path(a.root) / 'resources' / 'views' / 'filament' / 'pages' / 'platform-dashboard.blade.php'
    t = p.read_text()
    if 'MARKER-PATCH-139' in t:
        print('already_applied'); return
    if OLD not in t:
        print('ERROR: anchor missing', file=sys.stderr); sys.exit(2)
    if a.apply:
        p.write_text(t.replace(OLD, NEW, 1))
        print('applied')
    else:
        print('would_apply')

if __name__ == '__main__':
    main()
