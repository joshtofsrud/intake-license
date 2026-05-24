#!/usr/bin/env python3
"""
Patch 123 — Drop {subdomain} from tenant routing.

ResolveTenant middleware becomes the sole tenant-resolution mechanism.
Routes no longer carry a {subdomain} placeholder, so controller signatures
no longer need `string $subdomain`, and view route() calls no longer need
'subdomain' => ... in their parameter arrays.

Usage:
    python3 patch-123.py /path/to/intake-license            # dry-run
    python3 patch-123.py /path/to/intake-license --apply    # write changes

Idempotent: safe to re-run.
"""

import argparse
import pathlib
import re
import sys

# ─────────────────────────────────────────────────────────────────────
# Regexes (single source of truth)
# ─────────────────────────────────────────────────────────────────────

# Strip 'subdomain' => <value>, (or trailing ]). The value is bounded by
# either ',' (more keys after) or ']' (sole/last key). Verified against the
# full view + controller corpus — every occurrence matches this shape.
RE_KV_PAIR = re.compile(r"'subdomain'\s*=>\s*[^,\]]+,?\s*")

# Drop `string $subdomain` from method signatures. Trailing `, ` is also
# consumed if present, so we don't leave dangling commas. If it's the LAST
# parameter, the preceding `, ` is consumed instead — handled in a 2nd pass.
RE_SIG_WITH_TRAILING_COMMA   = re.compile(r'string\s+\$subdomain\s*,\s*')
RE_SIG_WITH_LEADING_COMMA    = re.compile(r',\s*string\s+\$subdomain\b')
RE_SIG_ONLY_PARAM            = re.compile(r'\bstring\s+\$subdomain\b')

# Helper call-sites where $subdomain is a positional arg (not inside a key=>value)
RE_STEPRESPONSE_CALL  = re.compile(r'(\$this->stepResponse\(\s*\d+\s*),\s*\$subdomain\s*,')
RE_REGISTERVIACASH    = re.compile(r'\$this->registerViaCash\(\s*\$subdomain\s*,\s*')
RE_RECEIVE_CALL       = re.compile(r'(\$this->receive\(\s*\$request)\s*,\s*\$subdomain\s*,')

# ─────────────────────────────────────────────────────────────────────
# Per-file transformations
# ─────────────────────────────────────────────────────────────────────

def strip_kv_pairs(text: str) -> tuple[str, int]:
    """Strip every 'subdomain' => X, or 'subdomain' => X (no trailing comma)."""
    n = len(RE_KV_PAIR.findall(text))
    return RE_KV_PAIR.sub('', text), n

def strip_sig(text: str) -> tuple[str, int]:
    """Remove `string $subdomain` parameter from method signatures, in any
    position. Order matters: consume trailing-comma form first, then
    leading-comma form (for last-param case), then bare token as safety net.
    """
    n = 0
    text, c = RE_SIG_WITH_TRAILING_COMMA.subn('', text); n += c
    text, c = RE_SIG_WITH_LEADING_COMMA.subn('', text);  n += c
    # Bare token only matches if it's the SOLE parameter, like `foo(string $subdomain)`
    text, c = RE_SIG_ONLY_PARAM.subn('', text);          n += c
    return text, n

def strip_helper_calls(text: str) -> tuple[str, int]:
    """Drop $subdomain positional args from the three known helper call-sites."""
    n = 0
    text, c = RE_STEPRESPONSE_CALL.subn(r'\1,', text); n += c
    text, c = RE_REGISTERVIACASH.subn('$this->registerViaCash(', text); n += c
    text, c = RE_RECEIVE_CALL.subn(r'\1,', text); n += c
    return text, n


# ─────────────────────────────────────────────────────────────────────
# routes/web.php — full rewrite (small file, structural change)
# ─────────────────────────────────────────────────────────────────────

ROUTES_HEAD_OLD = re.compile(
    r"\$domain\s*=\s*config\('intake\.domain',\s*'intake\.works'\);\s*\n"
    r"\$tenantHost\s*=\s*'\{subdomain\}\.'\s*\.\s*\$domain;\s*\n"
)
ROUTES_HEAD_NEW = "$domain = config('intake.domain', 'intake.works');\n"

ROUTES_TAIL_OLD_RE = re.compile(
    r"// ─+\n"
    r"// Register custom-domain track FIRST\..*?"
    r"->group\(\$tenantRoutes\);\s*\n+"
    r"// ─+\n"
    r"// Register subdomain track LAST.*?"
    r"->group\(\$tenantRoutes\);\s*\n*",
    re.DOTALL,
)

ROUTES_TAIL_NEW = (
    "// ─────────────────────────────────────────────────────────────────────\n"
    "// MARKER-PATCH-123 — Single tenant route registration. ResolveTenant\n"
    "// middleware identifies the tenant from the request host, supporting\n"
    "// both {slug}.intake.works subdomains and custom domains (Cloudflare\n"
    "// for SaaS). Routes carry no {subdomain} placeholder; controllers\n"
    "// resolve the current tenant via the tenant() helper / app('tenant').\n"
    "// ─────────────────────────────────────────────────────────────────────\n"
    "Route::middleware(['App\\Http\\Middleware\\ResolveTenant'])\n"
    "    ->group($tenantRoutes);\n"
)

# Also strip the obsolete comment block describing the two-track scheme
ROUTES_COMMENT_BLOCK_RE = re.compile(
    r"// =+\n"
    r"// Tenant routes — TWO-TRACK REGISTRATION \(MARKER-PATCH-121\).*?"
    r"// =+\n",
    re.DOTALL,
)
ROUTES_COMMENT_BLOCK_NEW = (
    "// =========================================================================\n"
    "// Tenant routes (MARKER-PATCH-123)\n"
    "//\n"
    "// Tenant-facing routes live inside the $tenantRoutes closure and are\n"
    "// registered once under the ResolveTenant middleware. The middleware\n"
    "// identifies the tenant from the request host:\n"
    "//\n"
    "//   - {slug}.intake.works  → tenants.subdomain lookup\n"
    "//   - any other host       → tenant_domains.hostname lookup (active rows)\n"
    "//\n"
    "// Unknown hosts 404 via middleware abort. No {subdomain} placeholder is\n"
    "// declared on the routes themselves, so route() URLs render relative;\n"
    "// in a tenant request the current host is used naturally.\n"
    "// =========================================================================\n"
)

def rewrite_routes(text: str) -> tuple[str, dict]:
    counts = {}

    # 1. Head: drop the $tenantHost variable
    text, c = ROUTES_HEAD_OLD.subn(ROUTES_HEAD_NEW, text)
    counts['head'] = c

    # 2. Comment block: replace the TWO-TRACK preamble
    text, c = ROUTES_COMMENT_BLOCK_RE.subn(ROUTES_COMMENT_BLOCK_NEW, text)
    counts['comment_block'] = c

    # 3. Tail: replace the two ->group($tenantRoutes) calls with one
    # Use lambda to bypass regex interpretation of \H etc in the replacement
    text, c = ROUTES_TAIL_OLD_RE.subn(lambda _m: ROUTES_TAIL_NEW, text)
    counts['tail'] = c

    return text, counts


# ─────────────────────────────────────────────────────────────────────
# helpers.php — surgical rewrite of tenant_url()
# ─────────────────────────────────────────────────────────────────────

HELPERS_OLD = """    function tenant_url(string $path = ''): string
    {
        $t = tenant();
        if (! $t) return url($path);

        $base = $t->custom_domain
            ? 'https://' . $t->custom_domain
            : 'https://' . $t->subdomain . '.' . config('intake.domain');

        return $base . '/' . ltrim($path, '/');
    }"""

HELPERS_NEW = """    function tenant_url(string $path = ''): string
    {
        $t = tenant();
        if (! $t) return url($path);

        // MARKER-PATCH-123 — delegate to Tenant::publicUrl() so custom
        // domains served via tenant_domains (and legacy custom_domain) are
        // both handled in one place.
        return $t->publicUrl() . '/' . ltrim($path, '/');
    }"""

def rewrite_helpers(text: str) -> tuple[str, int]:
    if HELPERS_OLD not in text:
        return text, 0
    return text.replace(HELPERS_OLD, HELPERS_NEW, 1), 1


# ─────────────────────────────────────────────────────────────────────
# ResolveTenant.php — drop URL::defaults and route setParameter blocks
# ─────────────────────────────────────────────────────────────────────

# Match the block from the comment line above URL::defaults through the
# closing brace of the `if ($route = ...)` block. Anchored by exact prefix
# so we don't accidentally eat the early-return logic.
RESOLVE_TENANT_BLOCK_OLD = re.compile(
    r"\n        // Inject `\{subdomain\}` into URL::defaults so every route\(\) call for\n"
    r"        // a tenant route works without having to pass `subdomain` explicitly\.\n"
    r"        // e\.g\. route\('tenant\.dashboard'\) Just Works on a subdomain request\.\n"
    r"        URL::defaults\(\['subdomain' => \$tenant->subdomain\]\);\n"
    r"\n"
    r"        // Also set it on the current route's parameters if a route has already\n"
    r"        // matched \(it hasn't, usually — this runs before SubstituteBindings —\n"
    r"        // but it's cheap insurance for controllers that read route params\)\.\n"
    r"        if \(\$route = \$request->route\(\)\) \{\n"
    r"            \$route->setParameter\('subdomain', \$tenant->subdomain\);\n"
    r"        \}\n"
)
RESOLVE_TENANT_BLOCK_NEW = (
    "\n        // MARKER-PATCH-123 — URL::defaults / route setParameter no longer\n"
    "        // needed: routes no longer carry a {subdomain} placeholder.\n"
)

def rewrite_resolve_tenant(text: str) -> tuple[str, int]:
    n = len(RESOLVE_TENANT_BLOCK_OLD.findall(text))
    return RESOLVE_TENANT_BLOCK_OLD.sub(RESOLVE_TENANT_BLOCK_NEW, text), n


# ─────────────────────────────────────────────────────────────────────
# Driver
# ─────────────────────────────────────────────────────────────────────

def process(root: pathlib.Path, apply: bool):
    summary = {
        'files_changed': 0,
        'kv_pairs_stripped': 0,
        'signatures_stripped': 0,
        'helper_calls_stripped': 0,
        'routes_rewrites': {},
        'helpers_rewrite': 0,
        'resolve_tenant_rewrite': 0,
    }

    # 1. routes/web.php
    p = root / 'routes' / 'web.php'
    text = p.read_text()
    new, counts = rewrite_routes(text)
    summary['routes_rewrites'] = counts
    if new != text:
        if apply: p.write_text(new)
        summary['files_changed'] += 1

    # 2. helpers.php
    p = root / 'app' / 'helpers.php'
    text = p.read_text()
    new, c = rewrite_helpers(text)
    summary['helpers_rewrite'] = c
    if new != text:
        if apply: p.write_text(new)
        summary['files_changed'] += 1

    # 3. ResolveTenant.php
    p = root / 'app' / 'Http' / 'Middleware' / 'ResolveTenant.php'
    text = p.read_text()
    new, c = rewrite_resolve_tenant(text)
    summary['resolve_tenant_rewrite'] = c
    if new != text:
        if apply: p.write_text(new)
        summary['files_changed'] += 1

    # 4. Controllers: signatures + helper calls + KV pairs inside route() arrays
    for f in (root / 'app' / 'Http' / 'Controllers' / 'Tenant').rglob('*.php'):
        text = f.read_text()
        original = text
        text, c1 = strip_sig(text);          summary['signatures_stripped'] += c1
        text, c2 = strip_helper_calls(text); summary['helper_calls_stripped'] += c2
        text, c3 = strip_kv_pairs(text);     summary['kv_pairs_stripped'] += c3
        if text != original:
            if apply: f.write_text(text)
            summary['files_changed'] += 1

    # 5. Views: KV pairs
    for f in (root / 'resources' / 'views').rglob('*.blade.php'):
        text = f.read_text()
        new, c = strip_kv_pairs(text)
        summary['kv_pairs_stripped'] += c
        if new != text:
            if apply: f.write_text(new)
            summary['files_changed'] += 1

    # 6. Mailables: KV pairs
    for f in (root / 'app' / 'Mail').rglob('*.php'):
        text = f.read_text()
        new, c = strip_kv_pairs(text)
        summary['kv_pairs_stripped'] += c
        if new != text:
            if apply: f.write_text(new)
            summary['files_changed'] += 1

    # 7. Orphan dead-code cleanup: `$sub = request()->route('subdomain');`
    # was used to feed 'subdomain' => $sub in this view's @php block. Once
    # the consumers are stripped (step 5), the assignment is dead. Single
    # known site; targeted match.
    p = root / 'resources' / 'views' / 'tenant' / 'classes' / 'sessions.blade.php'
    if p.exists():
        text = p.read_text()
        # Match the assignment line plus its trailing newline
        new = re.sub(
            r"  \$sub = request\(\)->route\('subdomain'\);\n",
            '',
            text,
        )
        if new != text:
            if apply: p.write_text(new)
            summary['files_changed'] += 1
            summary['orphan_sub_assignment'] = 1

    return summary


def verify(root: pathlib.Path) -> list[str]:
    """Return a list of failure messages. Empty list = all checks pass."""
    failures = []

    # Should be zero everywhere after a successful apply
    re_kv  = re.compile(r"'subdomain'\s*=>")
    re_sig = re.compile(r'string\s+\$subdomain\b')
    re_use = re.compile(r'\$subdomain\b')
    re_subdomain_route_token = re.compile(r'\{subdomain\}')

    # View KV pairs
    n = sum(len(re_kv.findall(f.read_text())) for f in (root / 'resources' / 'views').rglob('*.blade.php'))
    if n: failures.append(f"views still have {n} 'subdomain' => occurrences")

    # Controller signatures + body uses
    n_sig = n_use = 0
    for f in (root / 'app' / 'Http' / 'Controllers' / 'Tenant').rglob('*.php'):
        t = f.read_text()
        n_sig += len(re_sig.findall(t))
        n_use += len(re_use.findall(t))
    if n_sig: failures.append(f"controllers still have {n_sig} `string $subdomain` signatures")
    if n_use: failures.append(f"controllers still reference $subdomain in {n_use} places")

    # Mailables
    n_mail = sum(len(re_kv.findall(f.read_text())) for f in (root / 'app' / 'Mail').rglob('*.php'))
    if n_mail: failures.append(f"mailables still have {n_mail} 'subdomain' => occurrences")

    # routes/web.php
    rt = (root / 'routes' / 'web.php').read_text()
    if '$tenantHost' in rt:
        failures.append("routes/web.php still references $tenantHost")
    # Look for the actual {subdomain} placeholder used as a route or domain
    # parameter (e.g. ->domain('{subdomain}.intake.works')), excluding
    # comment lines that may discuss the absence of the placeholder.
    for i, line in enumerate(rt.splitlines(), start=1):
        if line.lstrip().startswith('//'):
            continue
        if '{subdomain}' in line:
            failures.append(f"routes/web.php line {i} still contains {{subdomain}} placeholder: {line.strip()}")
    if rt.count('->group($tenantRoutes)') != 1:
        failures.append(f"routes/web.php has {rt.count('->group($tenantRoutes)')} ->group($tenantRoutes) calls (expected 1)")

    # ResolveTenant
    rt_mw = (root / 'app' / 'Http' / 'Middleware' / 'ResolveTenant.php').read_text()
    if "URL::defaults(['subdomain'" in rt_mw:
        failures.append("ResolveTenant still calls URL::defaults(['subdomain' ...])")
    if "setParameter('subdomain'" in rt_mw:
        failures.append("ResolveTenant still calls setParameter('subdomain' ...)")

    # helpers.php
    helpers = (root / 'app' / 'helpers.php').read_text()
    if '$t->custom_domain' in helpers:
        failures.append("helpers.php tenant_url still references $t->custom_domain directly")

    return failures


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('root', help='Path to intake-license repo root')
    ap.add_argument('--apply', action='store_true', help='Write changes (default: dry-run)')
    args = ap.parse_args()

    root = pathlib.Path(args.root)
    if not (root / 'routes' / 'web.php').exists():
        print(f"ERROR: {root} does not look like an intake repo (no routes/web.php)", file=sys.stderr)
        sys.exit(2)

    mode = 'APPLY' if args.apply else 'DRY-RUN'
    print(f"=== patch-123 [{mode}] target={root} ===\n")

    summary = process(root, apply=args.apply)

    print("Summary:")
    for k, v in summary.items():
        print(f"  {k}: {v}")

    if args.apply:
        print("\nVerifying...")
        failures = verify(root)
        if failures:
            print("\nFAIL — leftovers found:")
            for f in failures: print(f"  - {f}")
            sys.exit(1)
        print("  all checks pass: zero leftover {subdomain} / 'subdomain' => / $subdomain references")
    else:
        print("\n(dry-run — no files written. Re-run with --apply to commit.)")


if __name__ == '__main__':
    main()
