#!/usr/bin/env python3
"""
Patch 124 — Admin on subdomain, customer portal on custom domain.

After patch 123, custom domains can reach every tenant route — including
/admin/*. But the global SESSION_DOMAIN=.intake.works in .env means
browsers refuse to set the session cookie on custom-domain hosts (a cookie
with Domain=.intake.works can't be set from www.spokanebike.com per
RFC 6265). Symptom: 419 CSRF mismatch on every form submit from a custom
domain because the session has no cookie to anchor to.

The long-term fix is to recognise two distinct kinds of tenant request:

    1. Subdomain ({slug}.intake.works) — admin AND customer surfaces.
       Session cookie scoped to .intake.works so impersonation from the
       master Filament panel continues to work (master admin signs in on
       intake.works/admin and the session must be readable on the tenant
       subdomain too).

    2. Custom domain (www.spokanebike.com, etc.) — customer surfaces only.
       Session cookie host-only (Domain attribute omitted). /admin/* is
       redirected to the tenant's subdomain so admin sessions remain
       anchored on .intake.works.

This patch modifies one file (app/Http/Middleware/ResolveTenant.php) to
track which match path produced the tenant, redirect /admin/* on custom
domains, and set config('session.domain') accordingly.

No .env change is required. SESSION_DOMAIN=.intake.works in production is
treated as the default for subdomain requests; the middleware overrides
to null on custom-domain requests before the session cookie is written.

Usage:
    python3 patch-124.py /path/to/intake-license            # dry-run
    python3 patch-124.py /path/to/intake-license --apply    # write changes

Idempotent: safe to re-run.
"""

import argparse
import pathlib
import sys


# ─────────────────────────────────────────────────────────────────────
# The rewritten ResolveTenant handle() body, surgically swapped in.
# We anchor on the "MARKER-PATCH-123" comment so this fails loudly if
# patch 123 hasn't been applied yet.
# ─────────────────────────────────────────────────────────────────────

OLD_BLOCK = """        if (! $tenant) {
            abort(404, 'Shop not found.');
        }

        // ----------------------------------------------------------------
        // Bind tenant into the application
        // ----------------------------------------------------------------
        app()->instance('tenant', $tenant);

        // Share with all Blade views
        view()->share('currentTenant', $tenant);

        // Tag the request so controllers/middleware can access it easily
        $request->attributes->set('tenant', $tenant);

        // MARKER-PATCH-123 — URL::defaults / route setParameter no longer
        // needed: routes no longer carry a {subdomain} placeholder.

        return $next($request);
    }"""

NEW_BLOCK = """        if (! $tenant) {
            abort(404, 'Shop not found.');
        }

        // ----------------------------------------------------------------
        // MARKER-PATCH-124 — Subdomain vs custom-domain enforcement
        //
        // Determine which match path produced the tenant. This drives two
        // behaviours below: admin redirect on custom domain, and the
        // session cookie Domain attribute.
        // ----------------------------------------------------------------
        $matchedViaSubdomain = str_ends_with($host, '.' . $rootDomain)
            && $tenant->subdomain === substr($host, 0, strlen($host) - strlen('.' . $rootDomain));

        // Admin is anchored to the tenant subdomain so that the master
        // admin's impersonation cookie (scoped to .intake.works) remains
        // valid. A custom-domain hit on /admin/* is redirected to the
        // canonical subdomain URL.
        if (! $matchedViaSubdomain && str_starts_with($request->path(), 'admin')) {
            $target = 'https://' . $tenant->subdomain . '.' . $rootDomain
                    . '/' . $request->path();
            if ($qs = $request->getQueryString()) {
                $target .= '?' . $qs;
            }
            return redirect($target, 301);
        }

        // Session cookie scoping. SESSION_DOMAIN=.intake.works in .env is
        // the default for subdomain requests (enables cross-subdomain
        // impersonation). On custom-domain requests we clear it so the
        // browser issues a host-only cookie — required because a cookie
        // with Domain=.intake.works cannot be set from a different
        // registrable domain (RFC 6265 §5.3 step 6).
        if (! $matchedViaSubdomain) {
            config(['session.domain' => null]);
        }

        // ----------------------------------------------------------------
        // Bind tenant into the application
        // ----------------------------------------------------------------
        app()->instance('tenant', $tenant);

        // Share with all Blade views
        view()->share('currentTenant', $tenant);

        // Tag the request so controllers/middleware can access it easily
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }"""


def process(root: pathlib.Path, apply: bool) -> dict:
    summary = {'files_changed': 0, 'block_swap': 0}

    p = root / 'app' / 'Http' / 'Middleware' / 'ResolveTenant.php'
    text = p.read_text()

    if 'MARKER-PATCH-124' in text:
        print("Already patched (MARKER-PATCH-124 present). No-op.")
        return summary

    if OLD_BLOCK not in text:
        print("ERROR: ResolveTenant.php does not contain the expected post-patch-123 block.", file=sys.stderr)
        print("Ensure patch 123 has been applied first.", file=sys.stderr)
        sys.exit(2)

    new = text.replace(OLD_BLOCK, NEW_BLOCK, 1)
    summary['block_swap'] = 1
    if new != text:
        if apply:
            p.write_text(new)
        summary['files_changed'] += 1

    return summary


def verify(root: pathlib.Path) -> list[str]:
    failures = []
    rt = (root / 'app' / 'Http' / 'Middleware' / 'ResolveTenant.php').read_text()

    if 'MARKER-PATCH-124' not in rt:
        failures.append("ResolveTenant.php missing MARKER-PATCH-124")
    if "config(['session.domain' => null])" not in rt:
        failures.append("ResolveTenant.php missing session.domain override")
    if "return redirect($target, 301)" not in rt:
        failures.append("ResolveTenant.php missing admin redirect")
    if '$matchedViaSubdomain' not in rt:
        failures.append("ResolveTenant.php missing $matchedViaSubdomain flag")

    return failures


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('root', help='Path to intake-license repo root')
    ap.add_argument('--apply', action='store_true', help='Write changes (default: dry-run)')
    args = ap.parse_args()

    root = pathlib.Path(args.root)
    if not (root / 'app' / 'Http' / 'Middleware' / 'ResolveTenant.php').exists():
        print(f"ERROR: {root} does not look like an intake repo", file=sys.stderr)
        sys.exit(2)

    mode = 'APPLY' if args.apply else 'DRY-RUN'
    print(f"=== patch-124 [{mode}] target={root} ===\n")

    summary = process(root, apply=args.apply)

    print("Summary:")
    for k, v in summary.items():
        print(f"  {k}: {v}")

    if args.apply:
        print("\nVerifying...")
        failures = verify(root)
        if failures:
            print("\nFAIL — leftovers found:")
            for f in failures:
                print(f"  - {f}")
            sys.exit(1)
        print("  all checks pass")
    else:
        print("\n(dry-run — no files written. Re-run with --apply to commit.)")


if __name__ == '__main__':
    main()
