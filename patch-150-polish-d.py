#!/usr/bin/env python3
"""
Patch 150-polish-d — finish the "sidebar is always dark" cleanup.

Three related fixes:

1. Remove dead theme-b sidebar overrides.
   When theme A existed, the sidebar was light in theme A/B and dark
   in theme C. Now sidebar is always dark. These overrides force the
   user dropdown to white background and apply light hover states
   when theme B is active — wrong against our dark sidebar:

     .ia-theme-b .ia-sb-user-menu                          (white bg)
     .ia-theme-b .ia-sb-user-menu-item:hover               (dark hover)
     .ia-theme-b .ia-sb-user-details > summary:hover       (dark hover)
     .ia-theme-b .ia-sb-location-wrap .ia-loc-switcher...  (dark hover)
     .ia-theme-b .ia-sidebar-identity                      (dark border)
     .ia-theme-b .ia-sidebar-bottom                        (dark border)

   The base styles already use light-on-dark colors that work in both
   themes. Remove the overrides.

2. Lock the location switcher text + dropdown to dark-sidebar colors.
   Same fix as user menu — the .ia-sb-location-wrap-scoped CSS only
   overrides positioning, not color. .ia-loc-switcher-details > summary
   color: var(--ia-text) leaks the page text colour into the dark
   sidebar; the dropdown menu bg uses var(--ia-surface-1) which is
   light in theme B.

3. Drop the duplicate hover rule polish-c added.
   polish-c added an !important hover rule. The base hover rule
   already does the right thing once the theme-b override is gone.

Idempotent.
"""

import argparse
import pathlib
import sys


# ============================================================
# 1. Remove dead theme-b sidebar block
# ============================================================
# Wholesale-delete the "Light theme polish" block.

OLD_THEME_B_BLOCK = """/* Light theme polish */
.ia-theme-b .ia-sidebar-identity {
  border-bottom-color: rgba(0,0,0,.08);
}
.ia-theme-b .ia-sb-user-details > summary:hover,
.ia-theme-b .ia-sb-user-details[open] > summary,
.ia-theme-b .ia-sb-location-wrap .ia-loc-switcher-details > summary:hover,
.ia-theme-b .ia-sb-location-wrap .ia-loc-switcher-details[open] > summary {
  background: rgba(0,0,0,.05);
}
.ia-theme-b .ia-sb-user-menu {
  background: #ffffff;
  border-color: rgba(0,0,0,.10);
  box-shadow: 0 8px 24px rgba(0,0,0,.12);
}
.ia-theme-b .ia-sb-user-menu-item:hover {
  background: rgba(0,0,0,.05);
}
.ia-theme-b .ia-sidebar-bottom {
  border-top-color: rgba(0,0,0,.08);
}"""

NEW_THEME_B_BLOCK = """/* MARKER-PATCH-150-POLISH-D — sidebar is always dark, theme-b overrides removed */"""


# ============================================================
# 2. Lock location switcher colours when inside sidebar
# ============================================================
# The base styles for the floating-pill version of the location switcher
# use var(--ia-text), var(--ia-surface-2/3), var(--ia-surface-1) which all
# flip with theme. When this same component is rendered inside the dark
# sidebar (via .ia-sb-location-wrap), it needs to stay dark.
#
# The existing .ia-sb-location-wrap scoped rules only override positioning.
# Append a block that locks colours.

ANCHOR_LOC_OVERRIDES_END = """.ia-sb-location-wrap .ia-loc-switcher-menu {
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  width: auto;
  min-width: 0;
}"""

EXTRA_LOC_OVERRIDES = """.ia-sb-location-wrap .ia-loc-switcher-menu {
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  width: auto;
  min-width: 0;
}

/* MARKER-PATCH-150-POLISH-D — lock location-switcher colours when inside the dark sidebar */
.ia-sb-location-wrap .ia-loc-switcher-details > summary {
  color: #f0f0f0;
}
.ia-sb-location-wrap .ia-loc-switcher-details > summary:hover,
.ia-sb-location-wrap .ia-loc-switcher-details[open] > summary {
  background: rgba(255, 255, 255, .07);
  border-color: transparent;
}
.ia-sb-location-wrap .ia-loc-switcher-menu {
  background: #1a1a1a;
  border-color: rgba(255, 255, 255, .12);
  box-shadow: 0 8px 24px rgba(0, 0, 0, .35);
}
.ia-sb-location-wrap .ia-loc-switcher-menu-label {
  color: rgba(255, 255, 255, .55);
}
.ia-sb-location-wrap .ia-loc-switcher-item {
  color: #f0f0f0;
}
.ia-sb-location-wrap .ia-loc-switcher-item:hover {
  background: rgba(255, 255, 255, .08);
}
.ia-sb-location-wrap .ia-loc-switcher-item.is-current {
  color: #BEF264;
}"""


# ============================================================
# 3. Drop the duplicate !important hover rule polish-c added
# ============================================================

OLD_DUP_HOVER = """.ia-sb-user-menu-item:hover {
  background: rgba(255,255,255,.08) !important;
}
.ia-sb-user-menu-item {"""

NEW_DUP_HOVER = """.ia-sb-user-menu-item {"""


# Plus update the lower (original) hover rule to also use a fixed dark colour
# rather than var(--ia-surface-3) which flips with theme.

OLD_BASE_HOVER = """.ia-sb-user-menu-item:hover {
  background: var(--ia-surface-3, rgba(255,255,255,.07));
}"""

NEW_BASE_HOVER = """.ia-sb-user-menu-item:hover {
  /* MARKER-PATCH-150-POLISH-D — dark sidebar, dark hover always */
  background: rgba(255, 255, 255, .08);
}"""


# ============================================================
# Apply
# ============================================================

def edit(path: pathlib.Path, old: str, new: str, label: str, marker: str, apply: bool) -> str:
    if not path.exists():
        return f'skipped (file missing): {label}'
    t = path.read_text()
    if marker in t:
        return f'already_applied: {label}'
    if old not in t:
        return f'ERROR: anchor not found for {label}'
    if t.count(old) > 1:
        return f'ERROR: anchor not unique for {label}'
    if apply:
        path.write_text(t.replace(old, new, 1))
    return f'{"applied" if apply else "would_apply"}: {label}'


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('root')
    ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    root = pathlib.Path(a.root)
    if not (root / 'routes' / 'web.php').exists():
        print('ERROR: not an intake repo', file=sys.stderr); sys.exit(2)
    mode = 'APPLY' if a.apply else 'DRY-RUN'
    print(f'=== patch-150-polish-d [{mode}] target={root} ===\n')

    base = root / 'public' / 'css' / 'tenant' / 'base.css'

    print('SIDEBAR cleanup:')
    print(f'  {edit(base, OLD_THEME_B_BLOCK, NEW_THEME_B_BLOCK, "remove theme-b sidebar overrides", "sidebar is always dark, theme-b overrides removed", a.apply)}')

    # Dup-hover removal: detect by counting :hover rule occurrences.
    # Before: 2 occurrences. After: 1.
    t = base.read_text()
    hover_count = t.count('.ia-sb-user-menu-item:hover {')
    if hover_count <= 1:
        print('  already_applied: drop duplicate !important hover')
    elif OLD_DUP_HOVER not in t:
        print('  ERROR: anchor not found for drop duplicate !important hover')
    else:
        if a.apply:
            base.write_text(t.replace(OLD_DUP_HOVER, NEW_DUP_HOVER, 1))
        print('  applied: drop duplicate !important hover')

    print(f'  {edit(base, OLD_BASE_HOVER, NEW_BASE_HOVER, "lock base user-menu hover to dark", "dark sidebar, dark hover always", a.apply)}')
    print(f'  {edit(base, ANCHOR_LOC_OVERRIDES_END, EXTRA_LOC_OVERRIDES, "append location-switcher dark locks", "lock location-switcher colours when inside the dark sidebar", a.apply)}')

    if a.apply:
        print('\nDeploy: git pull && systemctl restart php8.3-fpm')
        print('(no view cache to clear — only CSS changed)')


if __name__ == '__main__':
    main()
