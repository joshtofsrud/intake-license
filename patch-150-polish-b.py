#!/usr/bin/env python3
"""
Patch 150-polish-b — settings polish round 2.

Three changes:

1. Communication tab grouping fix.
   Email sender was marked .set-card--wide in polish-a, forcing it
   to span the row. That left SMS half-width with awkward dead space.
   Email is roughly the same shape as SMS — both are "channel setup"
   cards — so they should sit side-by-side. Remove the wide flag
   from Email, keep it on Notifications (which IS a row-spanner) and
   Web Analytics (placeholder until polish-c moves it to its own tab).

2. Theme toggle in user-menu dropdown.
   The Appearance tab exists solely for the admin theme picker — one
   setting doesn't earn a top-level tab. Move the toggle into the
   existing user-menu <details> dropdown (right above "Sign out"),
   matching how Linear/Cron/Notion handle it.

3. Delete Appearance tab.
   Remove tab button + pane. updateAppearance() handler stays in
   SettingsController since the new dropdown form still posts
   tab=appearance to that route.

Idempotent.
"""

import argparse
import pathlib
import sys
import re


# ============================================================
# 1. Communication grouping — un-widen Email sender details
# ============================================================
#
# polish-a added 'set-card--wide' to the outer ia-card div containing
# Email sender details. Remove it. Search for the unique combination:
# the line right after where polish-a injected, then walk forward.

# Two anchors to be safe — find the OUTER card div whose head contains
# "Email sender details" and strip the wide class from it.

def unwiden_card(t: str, title: str) -> tuple[str, bool]:
    """Inverse of widen_card from polish-a. Idempotent."""
    title_anchor = f'<span class="ia-card-title">{title}</span>'
    if title_anchor not in t:
        return t, False
    pos = t.find(title_anchor)
    back_window_start = max(0, pos - 1200)
    back_window = t[back_window_start:pos]

    pattern = re.compile(r'<div class="(ia-card[^"]*set-card--wide[^"]*)"')
    matches = list(pattern.finditer(back_window))
    if not matches:
        return t, False
    # Walk backwards, find the most recent ia-card opener (not -head)
    target = None
    for m in reversed(matches):
        classes = m.group(1)
        first = classes.split(' ', 1)[0]
        if first == 'ia-card':
            target = m
            break
    if target is None:
        return t, False

    abs_open = back_window_start + target.start()
    class_start = abs_open + len('<div class="')
    class_end = t.find('"', class_start)
    current = t[class_start:class_end]
    new_classes = ' '.join(c for c in current.split() if c != 'set-card--wide')
    return t[:class_start] + new_classes + t[class_end:], True


# ============================================================
# 2 + 3. Theme toggle in user menu + delete Appearance tab
# ============================================================

# 2a. Add a theme toggle to the user-menu dropdown.
# The existing structure (around line 41 of _sidebar.blade.php):
#   <div class="ia-sb-user-menu" role="menu">
#     <button ... onclick="...logout-form...submit()" role="menuitem">
#       ... Sign out
#     </button>
#   </div>
#
# We inject a theme toggle button just BEFORE the Sign out button.

OLD_SIGNOUT_BUTTON = """      <div class="ia-sb-user-menu" role="menu">
        <button type="button" class="ia-sb-user-menu-item"
                onclick="document.getElementById('logout-form').submit()"
                role="menuitem">"""

NEW_SIGNOUT_BUTTON = """      <div class="ia-sb-user-menu" role="menu">
        {{-- MARKER-PATCH-150-POLISH-B — theme toggle (light/dark) --}}
        <form method="POST" action="{{ route('tenant.settings.update') }}" id="theme-toggle-form" style="margin:0">
          @csrf @method('PATCH')
          <input type="hidden" name="tab" value="appearance">
          <input type="hidden" name="admin_theme" id="theme-toggle-value" value="{{ $adminTheme === 'c' ? 'b' : 'c' }}">
          <button type="submit" class="ia-sb-user-menu-item" role="menuitem">
            @if($adminTheme === 'c')
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="12" cy="12" r="4"/>
                <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>
              </svg>
              <span>Switch to light theme</span>
            @else
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
              </svg>
              <span>Switch to dark theme</span>
            @endif
          </button>
        </form>

        <button type="button" class="ia-sb-user-menu-item"
                onclick="document.getElementById('logout-form').submit()"
                role="menuitem">"""


# 3a. Remove the Appearance tab button.
OLD_APPEARANCE_TAB = '  <button type="button" class="set-tab"        data-tab="appearance"    role="tab">Appearance</button>\n'
NEW_APPEARANCE_TAB = ''


# 3b. Remove the entire Appearance pane.
# It runs from `<div class="set-pane" id="pane-appearance"` to its closing </div>.
# We bracket-delete from that opening div through to (and including) the matching </div>
# at the same depth. Simplest reliable approach: find the opening, find the next
# `<div class="set-pane"` opener (start of Payments pane) — everything between is
# Appearance. If no next pane, find the next `{{-- =====` separator comment.

APPEARANCE_PANE_START = '{{-- =====================================================================\n     APPEARANCE — admin theme\n     ===================================================================== --}}\n'
APPEARANCE_PANE_HEAD  = '<div class="set-pane" id="pane-appearance" role="tabpanel">\n'

# Anchor used to find where the Appearance pane ENDS — the start of the next pane.
PAYMENTS_PANE_HEAD = '<div class="set-pane" id="pane-payments" role="tabpanel">\n'


def remove_appearance_pane(t: str) -> tuple[str, bool]:
    """Delete everything from the APPEARANCE separator comment through the
    closing </div> just before the PAYMENTS pane. Idempotent."""
    if APPEARANCE_PANE_START not in t:
        return t, False
    start_idx = t.find(APPEARANCE_PANE_START)
    # End anchor: the next pane's separator comment OR the next pane's div, whichever comes first.
    # Look for the Payments pane header AFTER the start_idx.
    after = t[start_idx:]
    payments_in_after = after.find(PAYMENTS_PANE_HEAD)
    if payments_in_after == -1:
        return t, False
    # The Payments pane is preceded by its own separator comment block; find that.
    # Walk back from payments_in_after looking for "{{-- =====".
    abs_payments_start = start_idx + payments_in_after
    # Step back to capture the comment block before <div class="set-pane" id="pane-payments">
    # Search for the separator above:
    rev_search = t[start_idx:abs_payments_start]
    sep_idx = rev_search.rfind('{{-- =========')
    if sep_idx == -1:
        # Just cut to the pane head
        cut_end = abs_payments_start
    else:
        cut_end = start_idx + sep_idx
    return t[:start_idx] + t[cut_end:], True


# ============================================================
# Apply everything
# ============================================================

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('root')
    ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    root = pathlib.Path(a.root)
    if not (root / 'routes' / 'web.php').exists():
        print('ERROR: not an intake repo', file=sys.stderr); sys.exit(2)
    mode = 'APPLY' if a.apply else 'DRY-RUN'
    print(f'=== patch-150-polish-b [{mode}] target={root} ===\n')

    # 1. Un-widen Email sender details
    sv = root / 'resources' / 'views' / 'tenant' / 'settings' / 'index.blade.php'
    if not sv.exists():
        print('ERROR: settings view missing'); sys.exit(2)
    t = sv.read_text()

    t, changed = unwiden_card(t, 'Email sender details')
    print(f'  unwide Email sender details: {"applied" if changed else "already_applied or anchor missing"}')

    # 3. Remove Appearance tab button + pane
    if OLD_APPEARANCE_TAB in t:
        t = t.replace(OLD_APPEARANCE_TAB, NEW_APPEARANCE_TAB, 1)
        print('  removed Appearance tab button')
    else:
        print('  Appearance tab button: already_applied or not found')

    t, changed = remove_appearance_pane(t)
    print(f'  removed Appearance pane: {"applied" if changed else "already_applied"}')

    if a.apply:
        sv.write_text(t)

    # 2. Theme toggle into sidebar dropdown
    sb = root / 'resources' / 'views' / 'layouts' / 'tenant' / '_sidebar.blade.php'
    if not sb.exists():
        print('ERROR: sidebar view missing'); sys.exit(2)
    st = sb.read_text()
    if 'MARKER-PATCH-150-POLISH-B' in st:
        print('  theme toggle in user menu: already_applied')
    elif OLD_SIGNOUT_BUTTON not in st:
        print('  ERROR: sign-out anchor not found in sidebar')
        sys.exit(2)
    else:
        st = st.replace(OLD_SIGNOUT_BUTTON, NEW_SIGNOUT_BUTTON, 1)
        if a.apply:
            sb.write_text(st)
        print('  theme toggle injected into user menu')

    if a.apply:
        print('\nDeploy: git pull && php artisan view:clear && systemctl restart php8.3-fpm')


if __name__ == '__main__':
    main()
