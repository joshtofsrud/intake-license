#!/usr/bin/env python3
"""
Patch 150-polish-a — sidebar reorganization + settings grid layout.

Two visible improvements, both low-risk:

1. Sidebar split: the overloaded "Manage" group becomes two groups,
   "Manage" (ops-focused) and "Engage" (customer-facing). Suppressions
   and Campaigns move from Manage to Engage where they belong.

2. Settings page card grid: each .set-pane content area becomes a
   responsive 2-column grid (min 380px per card). Cards that need
   the full width get the new .set-card--wide class. Browser auto-
   collapses to single column on narrow screens.

Cards marked wide (because their internal content needs the space):
  - Drop-off methods (complex sub-form)
  - Logos (3 file inputs in a row)
  - Logo display size (full-width sliders + preview boxes)
  - Notifications (3-col toggle grid)
  - SMS provider (multi-field with help text)
  - Test email block within Email sender details (already inside its own card)
  - Web analytics (until it's moved to its own tab in polish-b)

Card mergers (Currency+Timezone, Logos+LogoSize) and the new
Integrations tab are deferred to patch 150-polish-b — bigger surgery.

Idempotent.
"""

import argparse
import pathlib
import sys


# ============================================================
# 1. SIDEBAR REORG
# ============================================================
# Split nav items into Manage (ops) + Engage (customer-facing).
# Move Suppressions and Campaigns into Engage. Re-order so they
# group naturally.
#
# Current 'manage' group contains (in order):
#   Team & access, Services, Resources, Work Order Fields,
#   Intake Form Editor, Capacity, Pages, Email, Suppressions,
#   Waitlist, Campaigns
#
# New 'manage' group (ops):
#   Team & access, Services, Resources, Work Order Fields,
#   Intake Form Editor, Capacity
#
# New 'engage' group (customer-facing):
#   Pages, Email, Suppressions, Waitlist, Campaigns

# We need to:
# (a) update the $groups array to add 'engage'
# (b) change the 'group' field on 5 nav items from 'manage' to 'engage'

OLD_GROUPS = "  $groups = ['manage' => 'Manage', 'settings' => 'Settings'];"
NEW_GROUPS = "  $groups = ['manage' => 'Manage', 'engage' => 'Engage', 'settings' => 'Settings'];"

# Items to switch from 'manage' to 'engage'. We anchor on each item's `'route'` line
# plus the following `'group' => 'manage',` to ensure uniqueness.

NAV_FLIPS = [
    # (route name, item label for log)
    ("tenant.pages.index",         "Pages"),
    ("tenant.emails.index",        "Email"),
    ("tenant.suppressions.index",  "Suppressions"),
    ("tenant.waitlist.index",      "Waitlist"),
    ("tenant.campaigns.index",     "Campaigns"),
]


def flip_nav_group(t: str, route_name: str) -> tuple[str, bool]:
    """
    Change a single nav item's group from 'manage' to 'engage'.
    Returns (new_text, did_change).
    """
    # The structure we're matching:
    #   'route'  => 'tenant.X.Y',
    #   'label'  => '...',
    #   'icon'   => '...',
    #   'group'  => 'manage',
    needle = f"'route'  => '{route_name}',"
    if needle not in t:
        return t, False
    # Find the position, then find the next "'group'  => 'manage'," within ~400 chars
    start = t.find(needle)
    if start == -1:
        return t, False
    end = start + 600
    window = t[start:end]
    old_group = "'group'  => 'manage',"
    new_group = "'group'  => 'engage',"
    if old_group not in window:
        # Already changed, or different group
        return t, False
    pos_in_window = window.find(old_group)
    abs_pos = start + pos_in_window
    new_t = t[:abs_pos] + new_group + t[abs_pos + len(old_group):]
    return new_t, True


def patch_nav(root: pathlib.Path, apply: bool) -> list:
    p = root / 'resources' / 'views' / 'layouts' / 'tenant' / '_nav-items.blade.php'
    if not p.exists():
        return ['ERROR: nav-items file missing']
    t = p.read_text()
    results = []

    # Idempotence: if 'engage' is already in $groups, assume nav already migrated.
    if "'engage'" in t:
        return ['already_applied: sidebar reorg']

    # (a) groups array
    if OLD_GROUPS not in t:
        return [f"ERROR: groups anchor not found"]
    t = t.replace(OLD_GROUPS, NEW_GROUPS, 1)
    results.append('groups array: added engage')

    # (b) flip 5 items
    for route, label in NAV_FLIPS:
        t, changed = flip_nav_group(t, route)
        results.append(f'  {label}: {"flipped to engage" if changed else "no change (anchor missing)"}')

    if apply:
        p.write_text(t)
    return results


# ============================================================
# 2. SETTINGS PAGE — GRID LAYOUT
# ============================================================
#
# Widen .set-section from 680px to fill, and convert .set-pane's direct
# children into a responsive grid. Cards opt-in to full-width with
# .set-card--wide.

OLD_PANE_CSS = """/* Panes */
.set-pane { display:none; }
.set-pane.active { display:block; }
.set-section { max-width:680px; }"""

NEW_PANE_CSS = """/* Panes */
.set-pane { display:none; }
.set-pane.active { display:block; }

/* MARKER-PATCH-150-POLISH-A — responsive card grid */
.set-section {
  display: block;
  max-width: 1200px;
}
/* Each card in a settings form becomes a grid cell.
   Cards default to ~half width (min 420px). Cards with .set-card--wide
   span the full row. Save bars and headers are always full-row. */
.set-section .ia-card {
  margin-bottom: 0;
}
.set-section--grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
  gap: 18px;
  align-items: start;
}
.set-section--grid .set-card--wide,
.set-section--grid .set-savebar {
  grid-column: 1 / -1;
}
@media (max-width: 880px) {
  .set-section--grid { grid-template-columns: 1fr; }
}"""


# Each <form class="set-section"> or <div class="set-section"> opens a settings
# pane's content area. We want to add 'set-section--grid' so the grid kicks in.
#
# There are 6 panes; each has its own <form class="set-section"> or
# <div class="set-section">. We need to convert all of them.

OLD_SECTION_OPEN_FORM = 'class="set-section" data-dirty-form'
NEW_SECTION_OPEN_FORM = 'class="set-section set-section--grid" data-dirty-form'

OLD_SECTION_OPEN_DIV = '<div class="set-section">'
NEW_SECTION_OPEN_DIV = '<div class="set-section set-section--grid">'


# Cards that need to be FULL-WIDTH. Each entry: (anchor string, label for log).
# Anchor is the FIRST line of the card so we can prepend 'set-card--wide' to its class.
#
# We match each card head text since that's invariant across cards. Then we walk
# back to find the opening tag and inject the class.

WIDE_CARD_TITLES = [
    'Drop-off methods',
    'Logos',
    'Logo display size',
    'Email sender details',
    'SMS provider',
    'Notifications',
    'Web analytics',
    'Booking page',
    'Account settings',
    'Plans',
    'Active integrations',
    'Stripe Connect',
    'Stripe configuration',
]


def widen_card(t: str, title: str) -> tuple[str, bool]:
    """
    Find a card with this title and add 'set-card--wide' to its OUTER
    <div class="ia-card ..."> (not the inner ia-card-head).
    Returns (new_text, did_change). Idempotent.
    """
    # Look for: <span class="ia-card-title">{title}</span>
    title_anchor = f'<span class="ia-card-title">{title}</span>'
    if title_anchor not in t:
        return t, False

    # From the title anchor, walk back to find the OUTER ia-card opener.
    # The pattern we want: <div class="ia-card ..."  (NOT ia-card-head, ia-card-title, ia-card-action)
    #
    # We use a regex with negative lookahead.
    import re
    pos = t.find(title_anchor)
    back_window_start = max(0, pos - 1200)
    back_window = t[back_window_start:pos]

    # Find all <div class="ia-card..." opens, filter to those whose class starts
    # exactly with "ia-card" + (space|") — i.e. not ia-card-head etc.
    pattern = re.compile(r'<div class="(ia-card[^"]*)"')
    matches = list(pattern.finditer(back_window))
    if not matches:
        return t, False

    # Walk matches backward, find the most recent one that's a real ia-card
    # (class starts with "ia-card" or "ia-card " — not "ia-card-")
    target = None
    for m in reversed(matches):
        classes = m.group(1)
        first_class = classes.split(' ', 1)[0]
        if first_class == 'ia-card':
            target = m
            break
    if target is None:
        return t, False

    abs_open = back_window_start + target.start()
    class_start = abs_open + len('<div class="')
    class_end = t.find('"', class_start)
    if class_end == -1:
        return t, False

    current_classes = t[class_start:class_end]
    if 'set-card--wide' in current_classes:
        return t, False  # already widened
    new_classes = current_classes + ' set-card--wide'
    new_t = t[:class_start] + new_classes + t[class_end:]
    return new_t, True


def patch_settings_view(root: pathlib.Path, apply: bool) -> list:
    p = root / 'resources' / 'views' / 'tenant' / 'settings' / 'index.blade.php'
    if not p.exists():
        return ['ERROR: settings view missing']
    t = p.read_text()
    results = []

    # 1. CSS: replace pane CSS
    if 'MARKER-PATCH-150-POLISH-A' in t:
        css_done = True
    else:
        css_done = False
        if OLD_PANE_CSS not in t:
            return ['ERROR: pane CSS anchor not found']
        t = t.replace(OLD_PANE_CSS, NEW_PANE_CSS, 1)
        results.append('CSS: pane grid layout')

    if css_done:
        results.append('already_applied: CSS')

    # 2. Add set-section--grid to all .set-section opens
    n_form = t.count(OLD_SECTION_OPEN_FORM)
    n_div  = t.count(OLD_SECTION_OPEN_DIV)
    if n_form > 0:
        t = t.replace(OLD_SECTION_OPEN_FORM, NEW_SECTION_OPEN_FORM)
        results.append(f'  set-section--grid added to {n_form} form panes')
    else:
        already = t.count('set-section set-section--grid')
        if already > 0:
            results.append('  set-section--grid: already_applied (forms)')
        else:
            results.append('  set-section--grid: no form anchors found')

    if n_div > 0:
        t = t.replace(OLD_SECTION_OPEN_DIV, NEW_SECTION_OPEN_DIV)
        results.append(f'  set-section--grid added to {n_div} div panes')

    # 3. Mark wide cards
    for title in WIDE_CARD_TITLES:
        t, changed = widen_card(t, title)
        if changed:
            results.append(f'  wide: {title}')
        # else: card title not present in this codebase, or already widened — silent

    if apply:
        p.write_text(t)
    return results


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('root')
    ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    root = pathlib.Path(a.root)
    if not (root / 'routes' / 'web.php').exists():
        print('ERROR: not an intake repo', file=sys.stderr)
        sys.exit(2)
    mode = 'APPLY' if a.apply else 'DRY-RUN'
    print(f'=== patch-150-polish-a [{mode}] target={root} ===\n')

    print('NAV:')
    for line in patch_nav(root, a.apply):
        print(f'  {line}')

    print('\nSETTINGS GRID:')
    for line in patch_settings_view(root, a.apply):
        print(f'  {line}')

    if a.apply:
        print('\nDeploy: git pull && php artisan view:clear && systemctl restart php8.3-fpm')


if __name__ == '__main__':
    main()
