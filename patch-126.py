#!/usr/bin/env python3
"""
Patch 126 — Preserve captured DCV records across the cert-active transition.

Background: Cloudflare for SaaS emits ssl.validation_records and
ssl.dcv_delegation_records ONLY while a hostname is in pending_validation
/ pending_issuance state. Once the cert is issued and ssl.status flips
to 'active', those fields disappear from the API response entirely.

Patch 125 captured the records on every sync but overwrote with null
when CF stopped emitting them. The net effect: an active domain's DCV
record fields end up null, the show view's preferredDcvRecord() guard
correctly hides the cert-validation section, and the tenant has no UI
record of which records they added.

This patch changes the sync behaviour: persist the new arrays only when
CF returned non-empty values. Otherwise leave the previously-captured
values in place. The records the tenant has in DNS today (and which
keep their cert renewing) stay visible in the collapsed <details>
section on the show view forever.

For tenants whose domains were already active when patch 125 deployed
(notably www.spokanebike.com), patch 125 captured null. Those rows need
manual backfill via tinker — see the deploy notes printed by this script.

Usage:
    python3 patch-126.py /path/to/intake-license             # dry-run
    python3 patch-126.py /path/to/intake-license --apply     # write changes

Idempotent.
"""

import argparse
import pathlib
import sys


# ─────────────────────────────────────────────────────────────────────
# DomainProvisioningService::syncFromCloudflare — guard the updates
# ─────────────────────────────────────────────────────────────────────

OLD_BLOCK = """        $updates = [
            'last_check_at'     => now(),
            'last_check_status' => $cfData['status'] ?? 'unknown',
            // MARKER-PATCH-125 — refresh gate-2 records on every sync.
            // CF rotates these around renewals, so we want the freshest set
            // surfaced on the show view.
            'cf_validation_records'     => ($cfData['ssl_validation_records']     ?? []) ?: null,
            'cf_dcv_delegation_records' => ($cfData['ssl_dcv_delegation_records'] ?? []) ?: null,
            'cf_validation_synced_at'   => now(),
        ];"""

NEW_BLOCK = """        $updates = [
            'last_check_at'     => now(),
            'last_check_status' => $cfData['status'] ?? 'unknown',
        ];

        // MARKER-PATCH-126 — preserve captured DCV records across the
        // cert-active transition. CF returns ssl.validation_records /
        // ssl.dcv_delegation_records only while validating; once
        // ssl.status flips to 'active' they disappear from the response.
        // Persist only when CF actually returned non-empty values so the
        // last-captured set stays available in the UI's collapsed
        // "DNS records on file" section indefinitely.
        $newValidation  = $cfData['ssl_validation_records']     ?? [];
        $newDelegation  = $cfData['ssl_dcv_delegation_records'] ?? [];
        if (!empty($newValidation)) {
            $updates['cf_validation_records']   = $newValidation;
            $updates['cf_validation_synced_at'] = now();
        }
        if (!empty($newDelegation)) {
            $updates['cf_dcv_delegation_records'] = $newDelegation;
            $updates['cf_validation_synced_at']   = now();
        }"""


def process(root: pathlib.Path, apply: bool) -> dict:
    summary = {}

    p = root / 'app' / 'Services' / 'DomainProvisioningService.php'
    text = p.read_text()

    if 'MARKER-PATCH-126' in text:
        summary['already_applied'] = 1
        return summary

    if OLD_BLOCK not in text:
        print("ERROR: anchor not found. Ensure patch 125 has been applied.", file=sys.stderr)
        sys.exit(2)

    new_text = text.replace(OLD_BLOCK, NEW_BLOCK, 1)
    if apply:
        p.write_text(new_text)
    summary['provisioning_sync_guard'] = 1

    return summary


def verify(root: pathlib.Path) -> list[str]:
    failures = []
    text = (root / 'app' / 'Services' / 'DomainProvisioningService.php').read_text()
    if 'MARKER-PATCH-126' not in text:
        failures.append("MARKER-PATCH-126 not found in DomainProvisioningService.php")
    if 'if (!empty($newValidation))' not in text:
        failures.append("Guard for validation records not present")
    if 'if (!empty($newDelegation))' not in text:
        failures.append("Guard for delegation records not present")
    return failures


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('root', help='Path to intake-license repo root')
    ap.add_argument('--apply', action='store_true')
    args = ap.parse_args()

    root = pathlib.Path(args.root)
    if not (root / 'routes' / 'web.php').exists():
        print(f"ERROR: {root} does not look like an intake repo", file=sys.stderr)
        sys.exit(2)

    mode = 'APPLY' if args.apply else 'DRY-RUN'
    print(f"=== patch-126 [{mode}] target={root} ===\n")

    summary = process(root, apply=args.apply)
    print("Summary:")
    for k, v in summary.items():
        print(f"  {k}: {v}")

    if args.apply:
        print("\nVerifying...")
        failures = verify(root)
        if failures:
            print("\nFAIL:")
            for f in failures:
                print(f"  - {f}")
            sys.exit(1)
        print("  all checks pass")
        print("\n---")
        print("Manual backfill required for domains that were already 'active'")
        print("when patch 125 deployed. The new guard only preserves records")
        print("CF emits during validation, so already-active rows stay NULL")
        print("until something refreshes them.")
        print()
        print("For www.spokanebike.com specifically, paste this tinker block:")
        print()
        print('  cd /var/www/intake')
        print('  php artisan tinker')
        print()
        print('Then in tinker (replace the array values with what you put in DNS):')
        print()
        print("  \\$d = App\\Models\\Tenant\\TenantDomain::where('hostname','www.spokanebike.com')->first();")
        print("  \\$d->forceFill([")
        print("      'cf_validation_records' => [['txt_name' => '_PASTE_HERE', 'txt_value' => 'PASTE_HERE']],")
        print("      'cf_dcv_delegation_records' => [['cname' => '_acme-challenge.www.spokanebike.com', 'cname_target' => 'PASTE_FROM_CF_DASHBOARD']],")
        print("      'cf_validation_synced_at' => now(),")
        print("  ])->save();")
        print()
        print("Look up the CF dashboard values at: SSL/TLS > Custom Hostnames > www.spokanebike.com")
    else:
        print("\n(dry-run — no files written. Re-run with --apply to commit.)")


if __name__ == '__main__':
    main()
