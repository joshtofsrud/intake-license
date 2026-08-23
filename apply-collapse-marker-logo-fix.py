#!/usr/bin/env python3
"""Collapsed rail: two fixes.

1. The impersonation marker used position:fixed, which escapes the
   sidebar and pins to the viewport — so it read as a stripe down the
   whole page rather than something belonging to the rail. Replaced with
   an inset box-shadow on the sidebar element itself, which stays put and
   still runs the full height of the rail.

   That needs the state on the <aside>, not just on the user row, so the
   blade gains the class there too.

2. The tenant logo carries an INLINE height (from $adminLogoHeight), and
   an inline style beats a stylesheet rule — so the earlier max-width
   never shrank it. Overridden explicitly for the collapsed rail.
Run from repo root: python3 apply-collapse-marker-logo-fix.py
"""
import sys

SIDEBAR = 'resources/views/layouts/tenant/_sidebar.blade.php'
CSS     = 'public/css/tenant/base.css'

# ---------------------------------------------------------------- blade
s = open(SIDEBAR).read()
old = '<aside class="ia-sidebar">'
new = '<aside class="ia-sidebar {{ is_impersonating() ? \'is-impersonating\' : \'\' }}">'

if new in s:
    print("SKIP (already applied): aside class")
elif old not in s:
    print("FAIL: <aside class=\"ia-sidebar\"> not found"); sys.exit(1)
else:
    open(SIDEBAR, 'w').write(s.replace(old, new, 1))
    print("OK: aside class")

# ---------------------------------------------------------------- css
css = open(CSS).read()
if 'MARKER-COLLAPSE-MARKER-FIX' in css:
    print("SKIP (already applied): css")
    print("Done."); sys.exit(0)

# Drop the escaping fixed-position rule from the polish patch.
old_rule = """html.ia-sb-collapsed .ia-sb-user-row.is-impersonating::after {
  content: '';
  position: fixed;
  top: 0; bottom: 0; left: 0;
  width: 3px;
  background: #FBBF24;
  pointer-events: none;
}"""
if old_rule in css:
    css = css.replace(old_rule, '', 1)
    print("OK: removed escaping fixed stripe")

css += """

/* MARKER-COLLAPSE-MARKER-FIX ------------------------------------------------
   The impersonation stripe was position:fixed, so it left the sidebar and
   painted down the whole viewport. An inset shadow on the rail itself stays
   where it belongs and still reads at a glance. */
.ia-sidebar.is-impersonating {
  box-shadow: inset 3px 0 0 #FBBF24;
}

/* The logo's height is set INLINE from $adminLogoHeight, and an inline style
   beats a stylesheet rule — which is why the earlier max-width did nothing.
   Override height directly for the rail. */
html.ia-sb-collapsed .ia-sidebar-logo img {
  height: 28px !important;
  width: auto !important;
  max-width: 40px !important;
  object-fit: contain;
}
html.ia-sb-collapsed .ia-sidebar-logo {
  justify-content: center;
  padding-left: 0;
  padding-right: 0;
}
"""
open(CSS, 'w').write(css)
print("OK: contained marker + logo shrink")
print("Done. No migration needed. view:clear after deploy.")
