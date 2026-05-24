#!/usr/bin/env python3
"""
Patch 131 — drop bogus tenantUser relation on the all-devices page.

TenantTrustedDevice has no tenantUser relation (devices are tenant-
scoped, not user-scoped — see patch 130). Patch 129's all-devices
audit view tried to eager-load it and render a per-device user badge.

This patch removes both.

Usage:
    python3 patch-131.py /path/to/intake-license             # dry-run
    python3 patch-131.py /path/to/intake-license --apply

Idempotent.
"""

import argparse
import pathlib
import sys


OLD_CTRL = """    public function devices()
    {
        $this->requireOwner();
        $tenant = tenant();
        $devices = TenantTrustedDevice::activeForTenant($tenant->id)
            ->with(['tenantUser'])
            ->orderBy('last_used_at', 'desc')
            ->get();
        return view('tenant.team.devices', compact('devices'));
    }"""

NEW_CTRL = """    // MARKER-PATCH-131 — no tenantUser relation; devices are tenant-scoped.
    public function devices()
    {
        $this->requireOwner();
        $tenant = tenant();
        $devices = TenantTrustedDevice::activeForTenant($tenant->id)
            ->orderBy('last_used_at', 'desc')
            ->get();
        return view('tenant.team.devices', compact('devices'));
    }"""


OLD_VIEW = """        <div class=\"td-label\">
          {{ $d->label ?: 'Unnamed device' }}
          @if($d->tenantUser)
            <span class=\"ia-badge\" style=\"font-size:10px\">{{ $d->tenantUser->name }}</span>
          @endif
        </div>"""

NEW_VIEW = """        {{-- MARKER-PATCH-131 — no per-device user; devices are tenant-scoped --}}
        <div class=\"td-label\">{{ $d->label ?: 'Unnamed device' }}</div>"""


EDITS = [
    ('app/Http/Controllers/Tenant/TeamController.php', OLD_CTRL, NEW_CTRL, 'devices() eager-load'),
    ('resources/views/tenant/team/devices.blade.php',  OLD_VIEW, NEW_VIEW, 'devices.blade per-row user badge'),
]


def process(root: pathlib.Path, apply: bool) -> dict:
    summary = {}
    for rel, old, new, label in EDITS:
        path = root / rel
        text = path.read_text()
        if old not in text:
            if new in text:
                summary[label] = 'already_applied'
                continue
            print(f'ERROR: anchor not found for {label} in {rel}', file=sys.stderr)
            sys.exit(2)
        if apply:
            path.write_text(text.replace(old, new, 1))
        summary[label] = 'edited'
    return summary


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('root')
    ap.add_argument('--apply', action='store_true')
    args = ap.parse_args()
    root = pathlib.Path(args.root)
    mode = 'APPLY' if args.apply else 'DRY-RUN'
    print(f'=== patch-131 [{mode}] target={root} ===\n')
    s = process(root, apply=args.apply)
    for k, v in s.items():
        print(f'  {k}: {v}')
    if not args.apply:
        print('\n(dry-run)')


if __name__ == '__main__':
    main()
