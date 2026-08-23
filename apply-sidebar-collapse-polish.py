#!/usr/bin/env python3
"""Collapsed sidebar: clean up what the first pass left behind.

At 60px the rail still showed several full-width things squeezed into it:
the tenant letter-mark at full size, the account row's caret, the
"Online" offline-sync row with its label and chevron, the location
switcher, and the attention row. Impersonating made it worse — the amber
tint sat on a row whose text was clipped, so it read as a coloured smear
with no explanation.

Fixes: everything textual in the rail is clipped consistently, the
letter-mark and avatar shrink, the offline-sync dot survives as a dot,
and impersonation becomes a full-height amber edge on the rail plus an
amber ring on the avatar — legible at 60px, and still obviously "you are
not yourself right now".
Run from repo root: python3 apply-sidebar-collapse-polish.py
"""
import sys

CSS = 'public/css/tenant/base.css'

MARK = '/* MARKER-SIDEBAR-COLLAPSE-POLISH'
css = open(CSS).read()
if MARK in css:
    print("SKIP (already applied)"); sys.exit(0)

if 'MARKER-SIDEBAR-COLLAPSE' not in css:
    print("FAIL: run apply-sidebar-collapse.py first"); sys.exit(1)

open(CSS, 'w').write(css + """

/* MARKER-SIDEBAR-COLLAPSE-POLISH -------------------------------------------
   The first pass clipped the obvious labels but left the rail cluttered:
   full-size logo mark, the account caret, the offline-sync row's text and
   chevron, the location switcher, the attention row. All of it is either
   hidden or shrunk here so 60px reads as a deliberate rail. */

html.ia-sb-collapsed .ia-sb-user-caret,
html.ia-sb-collapsed .io-srow span:not(.io-dot),
html.ia-sb-collapsed .io-chev,
html.ia-sb-collapsed .ia-sb-location-wrap {
  display: none !important;
}

/* The attention row is search + inbox + alerts — worth keeping, but it's a
   horizontal strip. Stack it. */
html.ia-sb-collapsed .ar-row {
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding-left: 0; padding-right: 0;
}

/* Keep the sync indicator as a dot — it's status, not a label. */
html.ia-sb-collapsed .io-status.io-srow {
  justify-content: center;
  padding-left: 0; padding-right: 0;
  gap: 0;
}

/* The letter-mark is sized for a 220px column. */
html.ia-sb-collapsed .ia-sidebar-logo-mark {
  width: 30px; height: 30px;
  font-size: 13px;
}
html.ia-sb-collapsed .ia-sb-user-avatar {
  width: 30px; height: 30px;
  font-size: 11px;
}

/* Impersonation at 60px: an amber edge down the whole rail plus a ring on
   the avatar. A tinted row with clipped text just looked like a smear. */
html.ia-sb-collapsed .ia-sidebar {
  position: relative;
}
html.ia-sb-collapsed .ia-sb-user-row.is-impersonating {
  background: none;
  box-shadow: none;
}
html.ia-sb-collapsed .ia-sb-user-row.is-impersonating .ia-sb-user-avatar {
  box-shadow: 0 0 0 2px #FBBF24;
}
html.ia-sb-collapsed .ia-sb-user-row.is-impersonating::after {
  content: '';
  position: fixed;
  top: 0; bottom: 0; left: 0;
  width: 3px;
  background: #FBBF24;
  pointer-events: none;
}

/* The toggle sat over the logo once centred; give the logo room. */
html.ia-sb-collapsed .ia-sidebar-logo { padding-top: 40px; padding-bottom: 4px; }
""")
print("OK: collapsed-rail polish")
print("Done. No migration needed. view:clear after deploy.")
