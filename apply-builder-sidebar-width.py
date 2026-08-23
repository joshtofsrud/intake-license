#!/usr/bin/env python3
"""The builder ignores the collapsed sidebar.

.pb2-shell is position:fixed with a hardcoded `left: 220px` — it escapes
the tenant layout's padding on purpose, but that also means it can't see
the sidebar shrink to 60px. Collapsing did nothing on the one screen
where the extra width matters most.

Fix: the sidebar width becomes a variable on :root, set once and updated
by the collapse class. The shell reads the variable, so anything else
that needs to line up with the sidebar can use it too instead of
copy-pasting another magic number.
Run from repo root: python3 apply-builder-sidebar-width.py
"""
import sys

EDIT = 'resources/views/tenant/pages/edit.blade.php'
CSS  = 'public/css/tenant/base.css'

# ---------------------------------------------------------------- variable
css = open(CSS).read()
if '--ia-sidebar-w' in css:
    print("SKIP (already applied): width variable")
else:
    css += """

/* MARKER-SIDEBAR-WIDTH-VAR — one source of truth for the sidebar's width.
   .pb2-shell (and anything else that escapes the layout with position:fixed)
   reads this instead of hardcoding 220px and silently breaking when the
   sidebar collapses. */
:root { --ia-sidebar-w: 220px; }
html.ia-sb-collapsed { --ia-sidebar-w: 60px; }
@media (max-width: 900px) {
  :root, html.ia-sb-collapsed { --ia-sidebar-w: 0px; }
}
"""
    open(CSS, 'w').write(css)
    print("OK: width variable")

# ---------------------------------------------------------------- shell
s = open(EDIT).read()
old = """  left: 220px;          /* tenant sidebar width */"""
new = """  /* MARKER-SIDEBAR-WIDTH-VAR — was a hardcoded 220px, which meant collapsing
     the sidebar left the builder exactly where it was. */
  left: var(--ia-sidebar-w, 220px);
  transition: left .16s ease;"""

if new.split('\n')[-2] in s:
    print("SKIP (already applied): shell offset")
elif old not in s:
    print("FAIL: `left: 220px` anchor not found in the builder shell"); sys.exit(1)
else:
    open(EDIT, 'w').write(s.replace(old, new, 1))
    print("OK: shell offset")

print("Done. No migration needed. view:clear after deploy.")
