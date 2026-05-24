#!/usr/bin/env python3
"""
Patch 128 — Design token consistency.

Two related fixes to stop visual drift across tenant admin pages.

1. Token aliases in base.css
   Eight CSS custom properties are referenced throughout the codebase but
   never defined: --ia-text-2, --ia-text-3, --ia-text-4, --ia-muted,
   --ia-dim, --ia-border-2, --ia-border2, --ia-surface-1, --ia-surface-3.
   Their ~180 references are split between author-supplied inline hex
   fallbacks (which vary file to file: #888, #c8c8c8, rgba(255,255,255,.5),
   rgba(255,255,255,.55), ...) and bare references with no fallback (which
   inherit the parent text/border/background color and effectively
   disappear into the surface).

   This patch defines them all as aliases pointing at the canonical
   tokens that base.css already declares (--ia-text-muted, --ia-text-dim,
   --ia-border-strong, --ia-surface, --ia-surface-2). All ~180 existing
   references resolve to the right value automatically. Inline fallback
   hex codes become dead weight but stay harmless (CSS prefers the
   variable when defined). No view file changes required.

2. .ds-title in the domain detail view
   The title (e.g. 'www.spokanebike.com' at the top of the page) is the
   only place in the tenant admin that uses --ia-font-mono on a heading.
   The convention across the rest of the codebase is mono-for-code-and-
   data-only, sans-serif for everything else. This patch drops the
   font-family override (so the title inherits Inter from <body>) and
   lowers font-weight from 800 to 600 to match what Inter actually loads
   on this surface (Google Fonts import requests weights 400;500;600 —
   anything higher gets synthesised by the browser and looks heavy).

Usage:
    python3 patch-128.py /path/to/intake-license             # dry-run
    python3 patch-128.py /path/to/intake-license --apply     # write

Idempotent.
"""

import argparse
import pathlib
import sys


# ─────────────────────────────────────────────────────────────────────
# 1. base.css alias block
# ─────────────────────────────────────────────────────────────────────

BASE_CSS_ANCHOR_OLD = """  /* Transitions */
  --ia-t:     .12s ease;
}
"""

BASE_CSS_ANCHOR_NEW = """  /* Transitions */
  --ia-t:     .12s ease;
}

/* --------------------------------------------------------------------------
   MARKER-PATCH-128 — Legacy token aliases

   These tokens are used in ~180 places across views and CSS but were
   never defined. CSS was either falling back to author-supplied inline
   hex codes (which varied file to file) or inheriting parent colour
   (effectively invisible). Aliasing each one to its canonical
   equivalent normalises the rendering across every surface.

   Do not remove until a follow-up patch sweeps the view files to use
   the canonical token names directly.
   -------------------------------------------------------------------------- */
:root {
  --ia-text-2:    var(--ia-text-muted);
  --ia-text-3:    var(--ia-text-dim);
  --ia-text-4:    var(--ia-text-dim);
  --ia-muted:     var(--ia-text-muted);
  --ia-dim:       var(--ia-text-dim);
  --ia-border-2:  var(--ia-border-strong);
  --ia-border2:   var(--ia-border-strong);
  --ia-surface-1: var(--ia-surface);
  --ia-surface-3: var(--ia-surface-2);
}
"""


# ─────────────────────────────────────────────────────────────────────
# 2. show.blade.php — .ds-title de-mono + weight correction
# ─────────────────────────────────────────────────────────────────────

SHOW_TITLE_OLD = "  .ds-title { font-size:22px; font-weight:800; letter-spacing:-0.02em; font-family:var(--ia-font-mono,monospace); }"

SHOW_TITLE_NEW = "  /* MARKER-PATCH-128 — mono reserved for code/data; titles inherit Inter from body. Weight reduced to 600 because Inter is only loaded in 400/500/600 (heavier renders synthetic). */\n  .ds-title { font-size:22px; font-weight:600; letter-spacing:-0.01em; }"


# ─────────────────────────────────────────────────────────────────────
# Driver
# ─────────────────────────────────────────────────────────────────────

def process(root: pathlib.Path, apply: bool) -> dict:
    summary = {}

    # 1. base.css
    base = root / 'public' / 'css' / 'tenant' / 'base.css'
    text = base.read_text()
    if 'MARKER-PATCH-128' in text:
        summary['base_css'] = 'already_applied'
    else:
        if BASE_CSS_ANCHOR_OLD not in text:
            print(f"ERROR: base.css anchor not found.", file=sys.stderr)
            print(f"  Looked for the closing brace of the first :root block (after --ia-t).", file=sys.stderr)
            sys.exit(2)
        if text.count(BASE_CSS_ANCHOR_OLD) > 1:
            print(f"ERROR: base.css anchor matches {text.count(BASE_CSS_ANCHOR_OLD)} times.", file=sys.stderr)
            sys.exit(2)
        new = text.replace(BASE_CSS_ANCHOR_OLD, BASE_CSS_ANCHOR_NEW, 1)
        if apply:
            base.write_text(new)
        summary['base_css'] = 'aliases_added'

    # 2. show.blade.php
    show = root / 'resources' / 'views' / 'tenant' / 'settings' / 'domains' / 'show.blade.php'
    text = show.read_text()
    if SHOW_TITLE_NEW in text:
        summary['show_title'] = 'already_applied'
    elif SHOW_TITLE_OLD not in text:
        print(f"ERROR: show.blade.php .ds-title anchor not found.", file=sys.stderr)
        sys.exit(2)
    else:
        new = text.replace(SHOW_TITLE_OLD, SHOW_TITLE_NEW, 1)
        if apply:
            show.write_text(new)
        summary['show_title'] = 'demonoed'

    return summary


def verify(root: pathlib.Path) -> list[str]:
    failures = []
    base_text = (root / 'public' / 'css' / 'tenant' / 'base.css').read_text()
    show_text = (root / 'resources' / 'views' / 'tenant' / 'settings' / 'domains' / 'show.blade.php').read_text()

    if 'MARKER-PATCH-128' not in base_text:
        failures.append("base.css missing MARKER-PATCH-128")
    for alias in ['--ia-text-2:', '--ia-text-3:', '--ia-muted:', '--ia-border-2:', '--ia-surface-3:']:
        if alias not in base_text:
            failures.append(f"base.css missing alias '{alias}'")

    if 'font-family:var(--ia-font-mono' in show_text and '.ds-title' in show_text:
        # Could be a legitimate other mono usage; check the .ds-title line specifically
        for line in show_text.splitlines():
            if '.ds-title' in line and 'font-family' in line and 'mono' in line:
                failures.append(f".ds-title still has mono font-family: {line.strip()}")
    return failures


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('root', help='Path to intake-license repo root')
    ap.add_argument('--apply', action='store_true')
    args = ap.parse_args()

    root = pathlib.Path(args.root)
    if not (root / 'public' / 'css' / 'tenant' / 'base.css').exists():
        print(f"ERROR: {root} does not look like an intake repo (no base.css)", file=sys.stderr)
        sys.exit(2)

    mode = 'APPLY' if args.apply else 'DRY-RUN'
    print(f"=== patch-128 [{mode}] target={root} ===\n")

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
    else:
        print("\n(dry-run — no files written. Re-run with --apply to commit.)")


if __name__ == '__main__':
    main()
