#!/usr/bin/env python3
"""
Patch 138 — break inline single-line @if/@endif in the dashboard.

The "expecting endif" parse error persists after patches 136 and 137.
The remaining suspect is line 202, which packs @if, HTML, {{ }}, and
@endif onto a single line. Modern Blade usually handles this, but
something in this view's compile is consistently failing. Easiest fix:
make it block form.

Also: drop the @if and @endif text from the patch-137 comment (which
contained the literal @if string). It's been parsed as a comment for
4 patches, but if anything is sniffing for raw @if tokens elsewhere
(linters, custom plugins), better to remove the ambiguity.

Idempotent.
"""

import argparse
import pathlib
import sys

OLD_LINE = '''    <div class="pd-hero-meta">
      @if($hero['uptime'])<b>{{ $hero['uptime'] }}</b>uptime@endif
    </div>'''

NEW_LINE = '''    {{-- MARKER-PATCH-138 — block-form to avoid blade parse failure --}}
    <div class="pd-hero-meta">
      @if($hero['uptime'])
        <b>{{ $hero['uptime'] }}</b>uptime
      @endif
    </div>'''

OLD_COMMENT = '''        {{-- MARKER-PATCH-137 — no inline @if inside opening tags; renders href via ternary --}}'''
NEW_COMMENT = '''        {{-- MARKER-PATCH-137 — href via ternary (was an inline conditional) --}}'''


EDITS = [
    (OLD_LINE,    NEW_LINE,    'hero-meta inline @if'),
    (OLD_COMMENT, NEW_COMMENT, 'patch-137 comment text'),
]


def main():
    ap = argparse.ArgumentParser(); ap.add_argument('root'); ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    p = pathlib.Path(a.root) / 'resources' / 'views' / 'filament' / 'pages' / 'platform-dashboard.blade.php'
    t = p.read_text()
    for old, new, label in EDITS:
        if old in t:
            t = t.replace(old, new, 1)
            print(f"{'applied' if a.apply else 'would_apply'}: {label}")
        elif new in t:
            print(f'already_applied: {label}')
        else:
            print(f'ERROR: anchor missing for {label}', file=sys.stderr); sys.exit(2)
    if a.apply:
        p.write_text(t)


if __name__ == '__main__':
    main()
