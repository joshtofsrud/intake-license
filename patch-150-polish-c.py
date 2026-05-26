#!/usr/bin/env python3
"""
Patch 150-polish-c — three polish bugs from earlier rounds.

1. User menu text invisible in light theme.
   .ia-sb-user-name uses var(--ia-text), which is dark in theme b.
   Sidebar is always dark (intentional design choice) so the user
   name renders dark-on-dark. Lock the user name + role to a light
   color regardless of page theme.

   Also locks the new theme-toggle menu items so they stay readable
   in both themes.

2. Web Analytics card is full page width instead of grid-aware.
   The card was placed outside the parent communication form. It's
   not wrapped in a .set-section--grid container, so set-card--wide
   has no grid to span. Wrap it in its own grid section.

3. Email Sender Details and SMS Provider cards have mismatched heights.
   Email has the test-send sub-block; SMS doesn't. The grid currently
   uses align-items: start, so cards size to their content. Change to
   align-items: stretch so cards in the same row are equal height.

Idempotent.
"""

import argparse
import pathlib
import sys


# ============================================================
# 1. User menu text colors
# ============================================================
# Patch the sidebar CSS in base.css to lock the user-menu items to
# sidebar-appropriate colors (light text on dark bg, regardless of theme).

OLD_USER_NAME_CSS = """.ia-sb-user-name {
  font-size: 13px;
  font-weight: 600;
  color: var(--ia-text, inherit);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  line-height: 1.2;
}"""

NEW_USER_NAME_CSS = """.ia-sb-user-name {
  font-size: 13px;
  font-weight: 600;
  /* MARKER-PATCH-150-POLISH-C — sidebar is always dark, lock text colour */
  color: #f0f0f0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  line-height: 1.2;
}"""


OLD_USER_ROLE_CSS = """.ia-sb-user-role {
  font-size: 11px;
  opacity: .6;
  margin-top: 1px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}"""

NEW_USER_ROLE_CSS = """.ia-sb-user-role {
  font-size: 11px;
  /* MARKER-PATCH-150-POLISH-C — sidebar is always dark, lock text colour */
  color: rgba(255, 255, 255, .55);
  margin-top: 1px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}"""


OLD_MENU_ITEM_CSS = """.ia-sb-user-menu-item {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 9px 10px;
  border: 0;
  background: transparent;
  border-radius: 6px;
  cursor: pointer;
  font-size: 13px;
  text-align: left;
  color: inherit;
}"""

NEW_MENU_ITEM_CSS = """.ia-sb-user-menu-item {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 9px 10px;
  border: 0;
  background: transparent;
  border-radius: 6px;
  cursor: pointer;
  font-size: 13px;
  text-align: left;
  /* MARKER-PATCH-150-POLISH-C — sidebar dropdown is always dark */
  color: #f0f0f0;
  font-family: inherit;
}"""


# Also fix the menu BG which currently uses var(--ia-surface-1, #1a1a1a).
# In theme b that's a light surface — wrong for our dark sidebar dropdown.
OLD_MENU_BG = """.ia-sb-user-menu {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  background: var(--ia-surface-1, #1a1a1a);
  border: 1px solid var(--ia-border, rgba(255,255,255,.12));
  border-radius: var(--ia-r-md, 8px);
  box-shadow: 0 8px 24px rgba(0,0,0,.35);
  padding: 4px;
  z-index: 100;
}"""

NEW_MENU_BG = """.ia-sb-user-menu {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  /* MARKER-PATCH-150-POLISH-C — sidebar dropdown matches sidebar (always dark) */
  background: #1a1a1a;
  border: 1px solid rgba(255,255,255,.12);
  border-radius: var(--ia-r-md, 8px);
  box-shadow: 0 8px 24px rgba(0,0,0,.35);
  padding: 4px;
  z-index: 100;
}
.ia-sb-user-menu-item:hover {
  background: rgba(255,255,255,.08) !important;
}"""


# ============================================================
# 2. Web Analytics card — wrap in its own grid section
# ============================================================
# Current Blade in settings/index.blade.php (around line 1009-1043):
#
#   </form>                                    <- closes communication form
#   {{-- MARKER-PATCH-150-FIX — Web analytics ... --}}
#   <div class="ia-card set-card--wide" style="margin-bottom: 20px;">
#     ...analytics content...
#   </div>
#
# We wrap the <div class="ia-card ..."> in <div class="set-section set-section--grid">.

OLD_ANALYTICS_OPEN = '''  </form>
  {{-- MARKER-PATCH-150-FIX — Web analytics card, outside parent form (HTML disallows nested forms) --}}
  <div class="ia-card set-card--wide" style="margin-bottom: 20px;">'''

NEW_ANALYTICS_OPEN = '''  </form>
  {{-- MARKER-PATCH-150-FIX — Web analytics card, outside parent form (HTML disallows nested forms) --}}
  {{-- MARKER-PATCH-150-POLISH-C — wrap in grid section so set-card--wide applies --}}
  <div class="set-section set-section--grid">
  <div class="ia-card set-card--wide" style="margin-bottom: 20px;">'''


# Now we need to close that extra wrapper. The analytics card ends like:
#       <button type="submit" class="ia-btn ia-btn--primary">Save analytics</button>
#       </div>
#     </form>
#   </div>           <- this closes the ia-card
#
# We need to add one more </div> after that to close the new wrapper.

OLD_ANALYTICS_CLOSE = """      <div style="margin-top: 14px;">
        <button type="submit" class="ia-btn ia-btn--primary">Save analytics</button>
      </div>
    </form>
  </div>
"""

NEW_ANALYTICS_CLOSE = """      <div style="margin-top: 14px;">
        <button type="submit" class="ia-btn ia-btn--primary">Save analytics</button>
      </div>
    </form>
  </div>
  </div>{{-- MARKER-PATCH-150-POLISH-C close grid wrapper --}}
"""


# ============================================================
# 3. Card heights — align-items: stretch
# ============================================================
OLD_GRID_CSS = """.set-section--grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
  gap: 18px;
  align-items: start;
}"""

NEW_GRID_CSS = """.set-section--grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
  gap: 18px;
  /* MARKER-PATCH-150-POLISH-C — same-row cards match heights */
  align-items: stretch;
}
.set-section--grid > .ia-card { display: flex; flex-direction: column; }"""


# ============================================================
# Apply
# ============================================================

def edit_file(path: pathlib.Path, old: str, new: str, label: str, marker: str, apply: bool) -> str:
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
    print(f'=== patch-150-polish-c [{mode}] target={root} ===\n')

    base = root / 'public' / 'css' / 'tenant' / 'base.css'
    sv   = root / 'resources' / 'views' / 'tenant' / 'settings' / 'index.blade.php'
    if not base.exists():
        print('ERROR: base.css missing'); sys.exit(2)
    if not sv.exists():
        print('ERROR: settings view missing'); sys.exit(2)

    print('SIDEBAR USER MENU (locks colours to dark sidebar):')
    for old, new, label, unique_marker in [
        (OLD_USER_NAME_CSS, NEW_USER_NAME_CSS, '.ia-sb-user-name colour',     'sidebar is always dark, lock text colour'),
        (OLD_USER_ROLE_CSS, NEW_USER_ROLE_CSS, '.ia-sb-user-role colour',     'color: rgba(255, 255, 255, .55)'),
        (OLD_MENU_ITEM_CSS, NEW_MENU_ITEM_CSS, '.ia-sb-user-menu-item colour', 'sidebar dropdown is always dark'),
        (OLD_MENU_BG,       NEW_MENU_BG,       '.ia-sb-user-menu bg + hover', 'sidebar dropdown matches sidebar'),
    ]:
        print(f'  {edit_file(base, old, new, label, unique_marker, a.apply)}')

    print('\nSETTINGS GRID + ANALYTICS:')
    print(f'  {edit_file(sv, OLD_GRID_CSS, NEW_GRID_CSS, "set-section--grid align-items: stretch", "same-row cards match heights", a.apply)}')
    print(f'  {edit_file(sv, OLD_ANALYTICS_OPEN, NEW_ANALYTICS_OPEN, "wrap analytics in grid section (open)", "wrap in grid section so set-card--wide applies", a.apply)}')
    print(f'  {edit_file(sv, OLD_ANALYTICS_CLOSE, NEW_ANALYTICS_CLOSE, "wrap analytics in grid section (close)", "POLISH-C close grid wrapper", a.apply)}')

    if a.apply:
        print('\nDeploy: git pull && php artisan view:clear && systemctl restart php8.3-fpm')


if __name__ == '__main__':
    main()
