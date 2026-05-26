#!/usr/bin/env python3
"""
Patch 150-fix — fix analytics card rendering + several latent bugs.

Bugs fixed:
  1. Analytics card was rendered as a <form class="ia-card"> nested INSIDE
     the parent Communication form. Nested forms are invalid HTML; browsers
     silently flatten them. Result: card visual treatment was lost (no
     border/background in screenshot). Same bug class as patch 144.

  2. Two save buttons compete on one tab. Restructure: put the card
     visually in the section but keep its form OUTSIDE the parent form.

  3. AnalyticsSettingsController signature took (string $subdomain, Request $request).
     Tenant admin routes don't pass $subdomain — only the master admin Filament
     routes do. Same bug pattern as patch 149's FunnelTrackController.

  4. Duplicate route registration in routes/web.php (idempotence escape).

  5. Same signature bug in SuppressionController (patch 147) — that's why
     the tenant suppressions page hasn't loaded.

Idempotent.
"""

import argparse
import pathlib
import sys


# ============================================================
# 1 + 2: rewrap analytics block in settings page
# ============================================================

OLD_VIEW_BLOCK = """    {{-- MARKER-PATCH-150 — Web analytics card --}}
    <form method="POST"
          action="{{ route('tenant.settings.analytics.update', ['subdomain' => $currentTenant->subdomain]) }}"
          class="ia-card" style="margin-bottom: 18px;">
      @csrf
      <div class="ia-card-head">
        <span class="ia-card-title">Web analytics</span>
      </div>
      <p style="color: var(--ia-text-muted, rgba(255,255,255,.62)); font-size: 13px; margin-bottom: 14px;">
        Connect Google Analytics 4 to your public-facing pages. We'll inject the tracking script automatically.
        Leave blank to disable.
      </p>
      <label class="ia-label">GA-4 measurement ID</label>
      <input type="text" name="analytics_ga4_id" class="ia-input mono"
             value="{{ old('analytics_ga4_id', $currentTenant->settings['analytics_ga4_id'] ?? '') }}"
             placeholder="G-XXXXXXXXXX"
             style="max-width: 320px; font-family: var(--ia-font-mono, 'JetBrains Mono', monospace);">
      <div class="ia-help" style="margin-top: 6px; font-size: 11.5px; color: var(--ia-text-dim, rgba(255,255,255,.42));">
        Find this in your GA-4 Admin → Data Streams → Measurement ID. Starts with <code>G-</code>.
      </div>
      @error('analytics_ga4_id')
        <div style="color: #F47373; font-size: 12px; margin-top: 6px;">{{ $message }}</div>
      @enderror
      <div style="margin-top: 14px;">
        <button type="submit" class="ia-btn ia-btn--primary">Save analytics</button>
      </div>
    </form>"""

# Rewrite as a placeholder block to be moved. Strip from inside parent form, then
# re-inject below in a sibling div outside the form.
PLACEHOLDER = """    {{-- MARKER-PATCH-150-FIX — Web analytics moved outside parent form --}}"""

# Block to insert AFTER the closing </form> of the Communication form.
NEW_OUTSIDE_BLOCK = """
  {{-- MARKER-PATCH-150-FIX — Web analytics card, outside parent form (HTML disallows nested forms) --}}
  <div class="ia-card" style="margin-bottom: 20px;">
    <div class="ia-card-head">
      <span class="ia-card-title">Web analytics</span>
    </div>
    <p style="font-size:13px;opacity:.5;margin-bottom:14px">
      Connect Google Analytics 4 to your public-facing pages. We'll inject the tracking script automatically.
      Leave blank to disable.
    </p>
    <form method="POST" action="{{ route('tenant.settings.analytics.update') }}">
      @csrf
      <div class="ia-form-group">
        <label class="ia-form-label">GA-4 measurement ID</label>
        <input type="text" name="analytics_ga4_id" class="ia-input"
               value="{{ old('analytics_ga4_id', $currentTenant->settings['analytics_ga4_id'] ?? '') }}"
               placeholder="G-XXXXXXXXXX"
               style="max-width: 320px; font-family: var(--ia-font-mono, 'JetBrains Mono', monospace);">
        <div style="font-size:11px;color:var(--ia-text-dim);margin-top:4px">
          Find this in your GA-4 Admin → Data Streams → Measurement ID. Starts with <code>G-</code>.
        </div>
      </div>
      @error('analytics_ga4_id')
        <div style="color: #F47373; font-size: 12px; margin-top: 6px;">{{ $message }}</div>
      @enderror
      <div style="margin-top: 14px;">
        <button type="submit" class="ia-btn ia-btn--primary">Save analytics</button>
      </div>
    </form>
  </div>
"""

# Anchor for where to inject the new block. We want it inside the
# Communication pane, just before the pane's closing </div>.
# The communication pane closes its <form>, then closes the pane.
# Find the unique closing pattern.

PANE_CLOSE_OLD = """          <button type="button" data-test-send class="ia-btn ia-btn--ghost">Send test</button>"""


# ============================================================
# 3: Fix AnalyticsSettingsController signature
# ============================================================

OLD_ANALYTICS_SIG = """    public function update(string $subdomain, Request $request)"""
NEW_ANALYTICS_SIG = """    public function update(Request $request)"""


# ============================================================
# 4: De-dupe route registration
# ============================================================

DUP_BLOCK_OLD = """            // MARKER-PATCH-150 — Web analytics settings (GA-4 etc)
            Route::post('/settings/analytics', [TenantControllers\\AnalyticsSettingsController::class, 'update'])->name('settings.analytics.update');
            // MARKER-PATCH-150 — Web analytics settings (GA-4 etc)
            Route::post('/settings/analytics', [TenantControllers\\AnalyticsSettingsController::class, 'update'])->name('settings.analytics.update');"""

DUP_BLOCK_NEW = """            // MARKER-PATCH-150 — Web analytics settings (GA-4 etc)
            Route::post('/settings/analytics', [TenantControllers\\AnalyticsSettingsController::class, 'update'])->name('settings.analytics.update');"""


# ============================================================
# 5: Fix SuppressionController (patch 147 bug, same pattern)
# ============================================================
# The view also generates URLs with subdomain param — fix that too.

OLD_SUP_INDEX_SIG = """    public function index(string $subdomain, Request $request)"""
NEW_SUP_INDEX_SIG = """    public function index(Request $request)"""

OLD_SUP_DESTROY_SIG = """    public function destroy(string $subdomain, int $id)"""
NEW_SUP_DESTROY_SIG = """    public function destroy(int $id)"""

OLD_SUP_STORE_SIG = """    public function store(string $subdomain, Request $request)"""
NEW_SUP_STORE_SIG = """    public function store(Request $request)"""

# And in the redirect on access denied — drop the subdomain arg
OLD_SUP_REDIRECT = """            return redirect()->route('tenant.dashboard', $subdomain);"""
NEW_SUP_REDIRECT = """            return redirect()->route('tenant.dashboard');"""


def fix_view(root: pathlib.Path, apply: bool) -> list:
    """
    Two-step surgery on settings/index.blade.php:
    (a) replace the old analytics <form class="ia-card"> with a placeholder
    (b) inject the corrected block AFTER the Communication form closes

    The Communication pane structure (from line 728+):
      <div class="set-pane" id="pane-communication">
        <form ...>      <- parent
          ...
          {{-- Email sender details --}}
          <div class="ia-card">...</div>
          {{-- SMS provider --}}
          <div class="ia-card">...</div>
          ...notification toggles, etc...
        </form>          <- close parent form
        <!-- INJECT HERE -->
      </div>           <- close pane

    We'll find the </form> that closes the Communication parent and inject right after it.
    """
    results = []
    p = root / 'resources' / 'views' / 'tenant' / 'settings' / 'index.blade.php'
    if not p.exists():
        return ['ERROR: settings view missing']
    t = p.read_text()

    if 'MARKER-PATCH-150-FIX' in t:
        return ['already_applied: view fixes']

    # Step (a): remove the broken block (must be inside the parent form)
    if OLD_VIEW_BLOCK not in t:
        return ['ERROR: old analytics block not found']
    t = t.replace(OLD_VIEW_BLOCK, '', 1)
    results.append('removed broken analytics form')

    # Step (b): find the Communication pane's closing </form>. The pane starts at
    # `<div class="set-pane" id="pane-communication">`. We need the FIRST </form>
    # within that pane.
    pane_marker = '<div class="set-pane" id="pane-communication"'
    pane_start = t.find(pane_marker)
    if pane_start == -1:
        return ['ERROR: pane-communication not found']

    # The end of the parent form within the pane is the </form> before the </div> that closes the pane.
    # The pane has: <div> <form>...</form> </div>. We need to insert AFTER </form>, BEFORE </div>.
    # Find the next </div> after pane_marker, which closes the pane itself.
    # But there are inner divs. Easier approach: locate `</form>\n</div>` pattern that's
    # within the pane.

    pane_end = t.find('<div class="set-pane"', pane_start + 1)
    if pane_end == -1:
        pane_end = len(t)
    pane_text = t[pane_start:pane_end]

    # Find LAST </form> within pane_text
    last_form_close = pane_text.rfind('</form>')
    if last_form_close == -1:
        return ['ERROR: no </form> found in communication pane']

    inject_at_global = pane_start + last_form_close + len('</form>')
    t = t[:inject_at_global] + NEW_OUTSIDE_BLOCK + t[inject_at_global:]
    results.append('injected analytics card after communication form')

    if apply:
        p.write_text(t)
    return results


def fix_controllers_and_routes(root: pathlib.Path, apply: bool) -> list:
    """Apply signature fixes + route dedupe."""
    results = []

    def edit(rel, old, new, label):
        p = root / rel
        if not p.exists():
            return f'skipped ({rel} missing): {label}'
        t = p.read_text()
        if old not in t:
            if new in t:
                return f'already_applied: {label}'
            return f'ERROR: anchor missing for {label}'
        if t.count(old) > 1:
            return f'ERROR: anchor not unique for {label}'
        if apply:
            p.write_text(t.replace(old, new, 1))
        return f'{"applied" if apply else "would_apply"}: {label}'

    # Analytics controller signature
    results.append(edit(
        'app/Http/Controllers/Tenant/AnalyticsSettingsController.php',
        OLD_ANALYTICS_SIG, NEW_ANALYTICS_SIG,
        'AnalyticsSettingsController::update signature'
    ))

    # Route dedupe — apply ONLY if the dup pattern is present
    p = root / 'routes' / 'web.php'
    if p.exists():
        t = p.read_text()
        if DUP_BLOCK_OLD in t:
            if apply:
                p.write_text(t.replace(DUP_BLOCK_OLD, DUP_BLOCK_NEW, 1))
            results.append('applied: route dedupe')
        else:
            # Already deduped or never had dup
            results.append('skipped: route not duplicated')

    # Suppression controller (patch 147) signature fixes
    results.append(edit(
        'app/Http/Controllers/Tenant/SuppressionController.php',
        OLD_SUP_INDEX_SIG, NEW_SUP_INDEX_SIG, 'SuppressionController::index signature'
    ))
    results.append(edit(
        'app/Http/Controllers/Tenant/SuppressionController.php',
        OLD_SUP_DESTROY_SIG, NEW_SUP_DESTROY_SIG, 'SuppressionController::destroy signature'
    ))
    results.append(edit(
        'app/Http/Controllers/Tenant/SuppressionController.php',
        OLD_SUP_STORE_SIG, NEW_SUP_STORE_SIG, 'SuppressionController::store signature'
    ))
    results.append(edit(
        'app/Http/Controllers/Tenant/SuppressionController.php',
        OLD_SUP_REDIRECT, NEW_SUP_REDIRECT, 'SuppressionController::index $subdomain redirect'
    ))

    return results


def fix_suppression_view(root: pathlib.Path, apply: bool) -> str:
    """
    Suppression view passes ['subdomain' => $currentTenant->subdomain] to
    route() helpers. Since the route no longer requires that param, the
    helper would still work (Laravel ignores extra params) — but it's
    misleading. Clean up anyway.
    """
    p = root / 'resources' / 'views' / 'tenant' / 'email' / 'suppressions.blade.php'
    if not p.exists():
        return 'skipped (file missing)'
    t = p.read_text()
    if "['subdomain' => $currentTenant->subdomain" not in t:
        return 'already_applied (no subdomain refs)'

    import re
    # Pattern 1: route('x', ['subdomain' => ...])  ->  route('x')
    new_t = re.sub(
        r"route\('([^']+)',\s*\['subdomain' => \$currentTenant->subdomain\]\)",
        r"route('\1')",
        t,
    )
    # Pattern 2: route('x', ['subdomain' => ..., 'foo' => $foo])  ->  route('x', ['foo' => $foo])
    new_t = re.sub(
        r"\['subdomain' => \$currentTenant->subdomain,\s*",
        "[",
        new_t,
    )
    # Pattern 3: route('x', ['subdomain' => ..., 'id' => $row->id])
    new_t = re.sub(
        r"\['subdomain' => \$currentTenant->subdomain,\s*'id' => ([^\]]+)\]",
        r"['id' => \1]",
        new_t,
    )

    if new_t == t:
        return 'ERROR: nothing replaced — refs present but regex missed'
    if apply:
        p.write_text(new_t)
    return 'edited' if apply else 'would_edit'


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
    print(f'=== patch-150-fix [{mode}] target={root} ===\n')

    for line in fix_view(root, a.apply):
        print(f'  view: {line}')

    for line in fix_controllers_and_routes(root, a.apply):
        print(f'  {line}')

    print(f'  suppression view refs: {fix_suppression_view(root, a.apply)}')

    if a.apply:
        print('\nDeploy: git pull && php artisan view:clear && systemctl restart php8.3-fpm')


if __name__ == '__main__':
    main()
