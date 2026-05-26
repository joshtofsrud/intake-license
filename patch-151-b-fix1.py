#!/usr/bin/env python3
"""
Patch 151-b-fix1 — fix funnel division-by-array bug.

In traffic.blade.php the funnel max-value calc did:
   $maxFunnel = max(array_map(fn ($s) => $s['count'], $funnel['steps']) ?: [1], [1]);

That's max(array, array) which compares the two arrays as scalars
and returns the larger ONE OF THEM (still an array). The follow-up
`max($maxFunnel, 1)` then returns the array because [N] > 1 in PHP's
loose array-to-scalar comparison.

Result: $maxFunnel is array_like, then `$count / $maxFunnel` blows up
on PHP 8 with "Unsupported operand types: int / array".

Fix: extract the max integer cleanly with one max() call on the array,
fall back to 1 if empty/zero.

Idempotent.
"""

import argparse
import pathlib
import sys


OLD = """    @php
      $maxFunnel = max(array_map(fn ($s) => $s['count'], $funnel['steps']) ?: [1], [1]);
      $maxFunnel = max($maxFunnel, 1);
    @endphp"""

NEW = """    @php
      // MARKER-PATCH-151B-FIX1 — max() of step counts, with 1 as floor
      $stepCounts = array_map(fn ($s) => (int) $s['count'], $funnel['steps']);
      $maxFunnel = !empty($stepCounts) ? max($stepCounts) : 0;
      $maxFunnel = max($maxFunnel, 1);
    @endphp"""


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('root')
    ap.add_argument('--apply', action='store_true')
    a = ap.parse_args()
    root = pathlib.Path(a.root)
    p = root / 'resources' / 'views' / 'tenant' / 'reports' / 'traffic.blade.php'
    if not p.exists():
        print('ERROR: traffic view missing', file=sys.stderr); sys.exit(2)
    t = p.read_text()
    if 'MARKER-PATCH-151B-FIX1' in t:
        print('already_applied'); return
    if OLD not in t:
        print('ERROR: anchor missing', file=sys.stderr); sys.exit(2)
    if a.apply:
        p.write_text(t.replace(OLD, NEW, 1))
        print('applied')
        print('Deploy: git pull && php artisan view:clear && systemctl restart php8.3-fpm')
    else:
        print('would_apply (dry-run)')


if __name__ == '__main__':
    main()
